<?php
declare(strict_types=1);

/**
 * Content approval workflow (#32, docs/modules/scheduling.md).
 *
 * Hotel setting `content_require_approval` (off by default). While it is on:
 *  - staff / reception (permission content.submit) may add and edit content; their saves wait for a
 *    manager (content.approve). Managers' own saves are approved immediately.
 *  - a NEW item of staff is stored with approval_status 'pending' (or 'draft' with "Save as draft") and
 *    never reaches a TV, a playlist slot, a layout zone, a schedule or a push until approved
 *    (ContentRules::playable, parse_source, Broadcaster::pushNow / validateSchedule).
 *  - a staff edit of APPROVED content is kept as a pending revision (table content_revisions); the
 *    approved version stays on air until a manager approves the revision (then it replaces the item)
 *    or rejects it (the item stays as it was, the revision shows the reason to its author).
 * Statuses: draft → pending → approved | rejected (a rejected item can be edited and re-submitted).
 * When the setting is off, every save is approved (2.3 behaviour). Items already waiting stay in the
 * Approvals queue until a manager decides.
 */
final class Approvals
{
    public const SETTING = 'content_require_approval';
    /** content_items columns a revision carries. */
    public const FIELDS = ['title', 'duration', 'url', 'body', 'settings', 'is_active', 'file_path', 'thumb_path', 'mime_type', 'file_size', 'valid_from', 'valid_to'];

    public static function enabled(): bool
    {
        // 2.5 plans: without "Content approval" in the plan, staff content is not held back (there is no
        // approvals page to release it).
        return Settings::bool(self::SETTING) && Features::enabled('approvals');
    }

    /** Does a save by the current user need a manager's approval? */
    public static function needsApproval(): bool
    {
        return self::enabled() && !Auth::can('content.approve');
    }

    /** May the current user add / edit content items (content.manage, or content.submit while approval is on)? */
    public static function canEdit(): bool
    {
        return Auth::can('content.manage') || (self::enabled() && Auth::can('content.submit'));
    }

    // ------------------------------------------------------------------ revisions

    /** Open revision of an item (data decoded), or null. */
    public static function revision(int $contentId): ?array
    {
        $r = DB::one('SELECT * FROM content_revisions WHERE content_id = :c AND hotel_id = :h', ['c' => $contentId, 'h' => Tenant::id()]);
        if (!$r) {
            return null;
        }
        $r['data'] = json_decode((string) $r['data'], true) ?: [];
        return $r;
    }

    /** The item as its open revision would make it (edit form / approval preview). */
    public static function merged(array $item, ?array $revision = null): array
    {
        $revision ??= isset($item['id']) ? self::revision((int) $item['id']) : null;
        if (!$revision) {
            return $item;
        }
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $revision['data'])) {
                $item[$f] = $revision['data'][$f];
            }
        }
        return $item;
    }

    /** Delete an item's open revision and the files only it used. */
    public static function discardRevision(int $contentId, ?array $item = null): void
    {
        $rev = self::revision($contentId);
        if (!$rev) {
            return;
        }
        DB::delete('content_revisions', 'id = :id', ['id' => $rev['id']]);
        self::deleteUnusedFiles($rev['data'], $item ?? (ContentManager::findOwn($contentId) ?? []));
    }

    /** Delete $data's files unless $keep uses the same path. */
    private static function deleteUnusedFiles(array $data, array $keep): void
    {
        $file = $data['file_path'] ?? null;
        $thumb = $data['thumb_path'] ?? null;
        $file = $file && $file !== ($keep['file_path'] ?? null) ? $file : null;
        $thumb = $thumb && $thumb !== ($keep['thumb_path'] ?? null) ? $thumb : null;
        if ($file || $thumb) {
            Uploader::delete($file, $thumb);
        }
    }

    // ------------------------------------------------------------------ saving

    /**
     * Save a content form ($row = content_items columns from admin/content.php) honouring approvals.
     * $oldFiles: files the save replaces ([file, thumb]) — deleted unless the old version stays on air.
     * Returns [id, outcome]: outcome 'approved' | 'pending' | 'draft' | 'revision' (pending revision) |
     * 'revision_draft'.
     */
    public static function save(?array $existing, array $row, string $type, bool $draft = false, ?array $oldFiles = null): array
    {
        $uid = Auth::id();
        $now = now();
        if (!self::needsApproval()) {
            $row += ['approval_status' => 'approved', 'approval_note' => null];
            if (self::enabled()) {
                $row += ['reviewed_by' => $uid, 'reviewed_at' => $now];
            }
            if ($existing) {
                DB::update('content_items', $row, 'id = :id', ['id' => $existing['id']]);
                $id = (int) $existing['id'];
                $after = array_merge($existing, $row);
                self::discardRevision($id, $after); // a manager's save replaces any waiting staff edit
            } else {
                $id = DB::insert('content_items', $row + ['type' => $type, 'created_by' => $uid, 'created_at' => $now]);
            }
            if ($oldFiles) {
                Uploader::delete($oldFiles[0], $oldFiles[1]);
            }
            Settings::bumpContentVersion();
            return [$id, 'approved'];
        }

        $status = $draft ? 'draft' : 'pending';
        if ($existing && ($existing['approval_status'] ?? 'approved') === 'approved') {
            // Keep the approved version on air: store the edit as the item's revision.
            $id = (int) $existing['id'];
            $prev = self::revision($id);
            $base = [];
            foreach (self::FIELDS as $f) {
                $base[$f] = $existing[$f] ?? null;
            }
            if ($prev) {
                $base = array_merge($base, array_intersect_key($prev['data'], array_flip(self::FIELDS)));
            }
            $data = array_merge($base, array_intersect_key($row, array_flip(self::FIELDS)));
            if ($prev) {
                // Files of the previous revision that the new one no longer uses (never the live item's).
                $keep = ['file_path' => $data['file_path'] ?? null, 'thumb_path' => $data['thumb_path'] ?? null];
                $prevData = $prev['data'];
                foreach (['file_path', 'thumb_path'] as $k) {
                    if (($prevData[$k] ?? null) === ($existing[$k] ?? null)) {
                        $prevData[$k] = null;
                    }
                }
                self::deleteUnusedFiles($prevData, $keep);
                DB::update('content_revisions', [
                    'data' => json_out($data), 'status' => $status, 'note' => null, 'submitted_by' => $uid,
                    'reviewed_by' => null, 'reviewed_at' => null,
                ], 'id = :id', ['id' => $prev['id']]);
            } else {
                DB::insert('content_revisions', ['content_id' => $id, 'data' => json_out($data), 'status' => $status, 'submitted_by' => $uid, 'created_at' => $now]);
            }
            if (!$draft) {
                self::notifyManagers((string) ($data['title'] ?? $existing['title']), true);
            }
            return [$id, $draft ? 'revision_draft' : 'revision'];
        }

        $row += ['approval_status' => $status, 'approval_note' => null, 'submitted_by' => $uid, 'reviewed_by' => null, 'reviewed_at' => null];
        if ($existing) {
            DB::update('content_items', $row, 'id = :id', ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = DB::insert('content_items', $row + ['type' => $type, 'created_by' => $uid, 'created_at' => $now]);
        }
        if ($oldFiles) {
            Uploader::delete($oldFiles[0], $oldFiles[1]);
        }
        Settings::bumpContentVersion();
        if (!$draft) {
            self::notifyManagers((string) ($row['title'] ?? ''), false);
        }
        return [$id, $status];
    }

    /** Submit a draft (item or revision) for approval. */
    public static function submit(int $contentId): bool
    {
        $item = ContentManager::find($contentId);
        if (!$item) {
            return false;
        }
        $rev = self::revision($contentId);
        if ($rev && in_array($rev['status'], ['draft', 'rejected'], true)) {
            DB::update('content_revisions', ['status' => 'pending', 'note' => null, 'submitted_by' => Auth::id()], 'id = :id', ['id' => $rev['id']]);
            self::notifyManagers((string) ($rev['data']['title'] ?? $item['title']), true);
            return true;
        }
        if (in_array($item['approval_status'], ['draft', 'rejected'], true)) {
            DB::update('content_items', ['approval_status' => 'pending', 'approval_note' => null, 'submitted_by' => Auth::id()], 'id = :id', ['id' => $contentId]);
            self::notifyManagers((string) $item['title'], false);
            return true;
        }
        return false;
    }

    private static function notifyManagers(string $title, bool $isEdit): void
    {
        $who = (string) (Auth::user()['full_name'] ?? '') ?: (string) (Auth::user()['username'] ?? '');
        ActivityLog::add($isEdit ? 'content_edit_submitted' : 'content_submitted', 'content', null, mb_substr($title, 0, 200));
        StaffAlerts::send('content.approve', __('Content waiting for approval'), $title . ($who !== '' ? ' · ' . $who : ''), 'approvals.php', 'approvals');
    }

    // ------------------------------------------------------------------ decisions

    /** Approve an item (or its pending revision). Returns false when there is nothing to approve. */
    public static function approve(int $contentId): bool
    {
        $item = ContentManager::find($contentId); // another hotel's id → 404
        if (!$item) {
            return false;
        }
        $rev = self::revision($contentId);
        $uid = Auth::id();
        $decided = ['reviewed_by' => $uid, 'reviewed_at' => now(), 'approval_note' => null];
        if ($rev && $rev['status'] === 'pending') {
            $data = array_intersect_key($rev['data'], array_flip(self::FIELDS));
            DB::transaction(function () use ($contentId, $data, $decided, $rev): void {
                DB::update('content_items', $data + ['approval_status' => 'approved', 'submitted_by' => $rev['submitted_by']] + $decided, 'id = :id', ['id' => $contentId]);
                DB::delete('content_revisions', 'id = :id', ['id' => $rev['id']]);
            });
            // Old files the approved revision replaced.
            self::deleteUnusedFiles(['file_path' => $item['file_path'], 'thumb_path' => $item['thumb_path']], $data);
            $author = (int) $rev['submitted_by'];
            $title = (string) ($data['title'] ?? $item['title']);
        } elseif (in_array($item['approval_status'], ['pending', 'draft', 'rejected'], true)) {
            DB::update('content_items', ['approval_status' => 'approved'] + $decided, 'id = :id', ['id' => $contentId]);
            $author = (int) ($item['submitted_by'] ?: $item['created_by']);
            $title = (string) $item['title'];
        } else {
            return false;
        }
        Settings::bumpContentVersion();
        ActivityLog::add('content_approved', 'content', $contentId, mb_substr($title, 0, 200) . self::authorNote($author));
        return true;
    }

    /** Reject an item (or its pending revision) with a reason shown to its author. */
    public static function reject(int $contentId, string $reason): bool
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new InvalidArgumentException(__('Give a reason for the rejection.'));
        }
        $item = ContentManager::find($contentId);
        if (!$item) {
            return false;
        }
        $rev = self::revision($contentId);
        $uid = Auth::id();
        if ($rev && $rev['status'] === 'pending') {
            DB::update('content_revisions', ['status' => 'rejected', 'note' => $reason, 'reviewed_by' => $uid, 'reviewed_at' => now()], 'id = :id', ['id' => $rev['id']]);
            $author = (int) $rev['submitted_by'];
            $title = (string) ($rev['data']['title'] ?? $item['title']);
        } elseif ($item['approval_status'] === 'pending') {
            DB::update('content_items', ['approval_status' => 'rejected', 'approval_note' => $reason, 'reviewed_by' => $uid, 'reviewed_at' => now()], 'id = :id', ['id' => $contentId]);
            Settings::bumpContentVersion();
            $author = (int) ($item['submitted_by'] ?: $item['created_by']);
            $title = (string) $item['title'];
        } else {
            return false;
        }
        ActivityLog::add('content_rejected', 'content', $contentId, mb_substr($title, 0, 200) . self::authorNote($author) . ' — ' . $reason);
        return true;
    }

    private static function authorNote(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $u = DB::value('SELECT username FROM users WHERE id = :id', ['id' => $userId]);
        return $u ? ' (@' . $u . ')' : '';
    }

    // ------------------------------------------------------------------ lists

    /**
     * Entries waiting for a decision: ['item' => row, 'revision' => row|null, 'author' => name].
     * $userId limits to one author ("my submissions": also drafts and rejected ones).
     */
    public static function entries(?int $userId = null): array
    {
        $h = Tenant::id();
        $statuses = $userId ? "('draft','pending','rejected')" : "('pending')";
        $p = ['h' => $h, 'h2' => $h];
        $mine = '';
        $mineRev = '';
        if ($userId) {
            $mine = ' AND COALESCE(c.submitted_by, c.created_by) = :u';
            $mineRev = ' AND r.submitted_by = :u2';
            $p += ['u' => $userId, 'u2' => $userId];
        }
        $items = DB::all(
            "SELECT c.*, u.username AS author_username, u.full_name AS author_name FROM content_items c
             LEFT JOIN users u ON u.id = COALESCE(c.submitted_by, c.created_by)
             WHERE c.hotel_id = :h AND c.approval_status IN $statuses$mine ORDER BY c.updated_at DESC, c.id DESC LIMIT 300",
            array_intersect_key($p, array_flip(['h', 'u']))
        );
        $revs = DB::all(
            "SELECT r.*, u.username AS author_username, u.full_name AS author_name FROM content_revisions r
             JOIN content_items c ON c.id = r.content_id AND c.hotel_id = :h2
             LEFT JOIN users u ON u.id = r.submitted_by
             WHERE r.hotel_id = :h AND r.status IN $statuses$mineRev ORDER BY r.updated_at DESC, r.id DESC LIMIT 300",
            array_intersect_key($p, array_flip(['h', 'h2', 'u2']))
        );
        $out = [];
        foreach ($items as $it) {
            $out[] = ['item' => $it, 'revision' => null, 'status' => $it['approval_status'], 'note' => $it['approval_note'],
                'author' => (string) ($it['author_name'] ?: $it['author_username']), 'at' => $it['updated_at']];
        }
        foreach ($revs as $r) {
            $item = ContentManager::findOwn((int) $r['content_id']);
            if (!$item) {
                continue;
            }
            $r['data'] = json_decode((string) $r['data'], true) ?: [];
            $out[] = ['item' => $item, 'revision' => $r, 'status' => $r['status'], 'note' => $r['note'],
                'author' => (string) ($r['author_name'] ?: $r['author_username']), 'at' => $r['updated_at']];
        }
        usort($out, static fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return $out;
    }

    public static function pendingCount(): int
    {
        $h = ['h' => Tenant::id()];
        return (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :h AND approval_status = 'pending'", $h)
            + (int) DB::value("SELECT COUNT(*) FROM content_revisions WHERE hotel_id = :h AND status = 'pending'", $h);
    }

    /** Rejected submissions of a user (badge in the menu). */
    public static function rejectedCount(int $userId): int
    {
        $p = ['h' => Tenant::id(), 'u' => $userId];
        return (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :h AND approval_status = 'rejected' AND COALESCE(submitted_by, created_by) = :u", $p)
            + (int) DB::value("SELECT COUNT(*) FROM content_revisions WHERE hotel_id = :h AND status = 'rejected' AND submitted_by = :u", $p);
    }

    /** Badge HTML for an approval status ('' for approved without open revision). */
    public static function badge(string $status, ?array $revision = null): string
    {
        if ($revision) {
            return match ($revision['status']) {
                'pending' => '<span class="badge text-bg-warning"><i class="bi bi-hourglass"></i> ' . e(__('Change waiting for approval')) . '</span>',
                'rejected' => '<span class="badge text-bg-danger"><i class="bi bi-x-circle"></i> ' . e(__('Change rejected')) . '</span>',
                default => '<span class="badge text-bg-light border"><i class="bi bi-pencil"></i> ' . e(__('Draft change')) . '</span>',
            };
        }
        return match ($status) {
            'pending' => '<span class="badge text-bg-warning"><i class="bi bi-hourglass"></i> ' . e(__('Waiting for approval')) . '</span>',
            'rejected' => '<span class="badge text-bg-danger"><i class="bi bi-x-circle"></i> ' . e(__('Rejected')) . '</span>',
            'draft' => '<span class="badge text-bg-light border"><i class="bi bi-pencil"></i> ' . e(__('Draft')) . '</span>',
            default => '',
        };
    }

    /** content id => revision status for the content list. */
    public static function revisionMap(): array
    {
        $out = [];
        foreach (DB::all('SELECT content_id, status FROM content_revisions WHERE hotel_id = :h', ['h' => Tenant::id()]) as $r) {
            $out[(int) $r['content_id']] = ['status' => $r['status']];
        }
        return $out;
    }
}
