<?php
declare(strict_types=1);

/** Logged-in admin panel session over HTTP (cookie jar + CSRF token) for integration tests. */
final class AdminSession
{
    public string $jar;
    public string $csrf = '';

    public function __construct(public string $base, public string $user, string $password = 'Passw0rd!')
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'adm');
        [, , $html] = TestEnv::http('GET', $base . 'admin/login.php', null, [], $this->jar);
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
        TestEnv::http('POST', $base . 'admin/login.php', null, [], $this->jar, ['_csrf' => html_entity_decode($m[1] ?? ''), 'username' => $user, 'password' => $password]);
        [, , $html] = TestEnv::http('GET', $base . 'admin/profile.php', null, [], $this->jar);
        preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
        $this->csrf = html_entity_decode($m[1] ?? '');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{0:int,1:mixed,2:string,3:string} */
    public function get(string $path): array
    {
        return TestEnv::http('GET', $this->base . 'admin/' . $path, null, [], $this->jar);
    }

    public function post(string $path, array $fields): array
    {
        $flat = ['_csrf' => $this->csrf];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $x) {
                    $flat[$k . '[' . $i . ']'] = (string) $x;
                }
            } else {
                $flat[$k] = (string) $v;
            }
        }
        return TestEnv::http('POST', $this->base . 'admin/' . $path, null, [], $this->jar, $flat);
    }

    public function ajax(string $action, ?array $json = null): array
    {
        $h = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'X-CSRF-Token: ' . $this->csrf];
        return TestEnv::http($json === null ? 'GET' : 'POST', $this->base . 'admin/ajax.php?action=' . $action, $json, $h, $this->jar);
    }
}
