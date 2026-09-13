<?php
/**
 * Include inside any .invoice block (requires $pdo and functions.php already loaded).
 * Renders the company name + contact details at the top of printed documents.
 */
$__companyName    = getSetting($pdo, 'company_name', '');
$__companyAddress = getSetting($pdo, 'address', '');
$__companyPhone   = getSetting($pdo, 'phone', '');
$__companyEmail   = getSetting($pdo, 'email', '');
$__companyWebsite = getSetting($pdo, 'website', '');
$__contactParts   = array_filter([$__companyPhone, $__companyEmail, $__companyWebsite]);
?>
<?php if ($__companyName || $__companyAddress || $__contactParts): ?>
<div class="company-header">
    <?php if ($__companyName): ?><div class="company-name"><?= h($__companyName) ?></div><?php endif; ?>
    <?php if ($__companyAddress): ?><div class="company-line"><?= h($__companyAddress) ?></div><?php endif; ?>
    <?php if ($__contactParts): ?><div class="company-line"><?= h(implode(' | ', $__contactParts)) ?></div><?php endif; ?>
</div>
<?php endif; ?>
