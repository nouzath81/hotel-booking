<?php
require __DIR__ . '/includes/auth.php';
requireAdmin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Settings';
$errors = [];
$saved = false;

// Save company info
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $fields = ['company_name', 'address', 'phone', 'email', 'website', 'footer_note', 'pos_tax_percent'];
    foreach ($fields as $f) {
        setSetting($pdo, $f, trim($_POST[$f] ?? ''));
    }
    $saved = true;
}

// Add a new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role = $_POST['role'] === 'admin' ? 'admin' : 'staff';

    if ($username === '') $errors[] = 'Username is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            header('Location: settings.php?user_added=1');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'That username is already taken.';
        }
    }
}

// Delete a user (can't delete yourself)
if (isset($_GET['delete_user'])) {
    $delId = (int) $_GET['delete_user'];
    if ($delId !== (int) $_SESSION['user_id']) {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$delId]);
    }
    header('Location: settings.php?user_deleted=1');
    exit;
}

$settings = getSettings($pdo);
$users = listUsers($pdo);

require __DIR__ . '/includes/header.php';
?>

<h1>Settings</h1>

<?php if ($saved): ?><div class="alert alert-success">Company details saved.</div><?php endif; ?>
<?php if (isset($_GET['user_added'])): ?><div class="alert alert-success">User added.</div><?php endif; ?>
<?php if (isset($_GET['user_deleted'])): ?><div class="alert alert-success">User removed.</div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Company Name &amp; Contact Details</h3>
    <p style="color:var(--muted);font-size:0.85rem;margin-top:-6px;">
        Shown in the site header and on every printed invoice, bill, statement, and report.
    </p>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Company Name</label>
                <input type="text" name="company_name" value="<?= h($settings['company_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" value="<?= h($settings['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= h($settings['email'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" rows="2"><?= h($settings['address'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Website</label>
                <input type="text" name="website" placeholder="e.g. www.yourhotel.com" value="<?= h($settings['website'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Print Footer Note</label>
                <input type="text" name="footer_note" placeholder="e.g. Thank you for your business!" value="<?= h($settings['footer_note'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Default POS Tax (%)</label>
                <input type="number" step="0.01" min="0" name="pos_tax_percent" value="<?= h($settings['pos_tax_percent'] ?? '0') ?>">
            </div>
        </div>
        <button type="submit" name="save_company" value="1" class="btn btn-accent">Save Company Details</button>
    </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
    <h3 style="padding:20px 20px 0;">Users</h3>
    <table>
        <thead>
            <tr><th>Username</th><th>Role</th><th>Created</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= h($u['username']) ?></td>
                <td><?= h(ucfirst($u['role'])) ?></td>
                <td><?= h(date('d M Y', strtotime($u['created_at']))) ?></td>
                <td>
                    <?php if ((int) $u['id'] !== (int) $_SESSION['user_id']): ?>
                        <a class="btn btn-sm btn-danger" href="settings.php?delete_user=<?= $u['id'] ?>"
                           onclick="return confirm('Remove user &quot;<?= h($u['username']) ?>&quot;?');">Delete</a>
                    <?php else: ?>
                        <span style="color:var(--muted);font-size:0.85rem;">(you)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Add User</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" minlength="6" required>
        </div>
        <div class="form-group">
            <label>Role</label>
            <select name="role">
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" name="add_user" value="1" class="btn btn-accent">Add User</button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
