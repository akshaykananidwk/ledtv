<?php
/**
 * 2.3: slide designer (#11) and PDF → slides import (#12): tenant tables (core/Designer.php).
 * Pages and AJAX actions use the content permissions (content.view / content.manage).
 */
declare(strict_types=1);

Tenant::registerTable('designs');
Tenant::registerTable('pdf_imports');
Tenant::registerTable('pdf_import_pages');
