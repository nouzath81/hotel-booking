<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';
$pageTitle = 'POS Accounts';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    $name = trim($_POST['account_name'] ?? '');
    $type = trim($_POST['account_type'] ?? 'Cash');
    $opening = (float)($_POST['opening_balance'] ?? 0);
    if ($name === '') $errors[] = 'Account name is required.';
    if (!$errors) { try { $st=$pdo->prepare('INSERT INTO pos_accounts (account_name,account_type,opening_balance) VALUES (?,?,?)'); $st->execute([$name,$type,$opening]); header('Location: pos_accounts.php?saved=1'); exit; } catch(PDOException $e){ $errors[]='Account name already exists.'; } }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle'])) {
    $st=$pdo->prepare('UPDATE pos_accounts SET active=? WHERE id=?'); $st->execute([(int)$_POST['active'] ? 0 : 1,(int)$_POST['id']]); header('Location: pos_accounts.php'); exit;
}
if (isset($_GET['delete'])) { $st=$pdo->prepare('DELETE FROM pos_accounts WHERE id=?'); $st->execute([(int)$_GET['delete']]); header('Location: pos_accounts.php?deleted=1'); exit; }
$accounts=posAccountList($pdo,false);
require __DIR__ . '/includes/header.php';
?>
<div class="flex-between"><h1>POS Accounts</h1><a href="pos.php" class="btn btn-accent">← POS Terminal</a></div>
<?php if(isset($_GET['saved'])):?><div class="alert alert-success">POS account added.</div><?php endif;?>
<?php if(isset($_GET['deleted'])):?><div class="alert alert-success">POS account deleted.</div><?php endif;?>
<?php if($errors):?><div class="alert alert-error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif;?>
<div class="card"><h3>Add POS Account</h3><form method="post" class="form-row" style="align-items:flex-end"><div class="form-group"><label>Account Name</label><input name="account_name" placeholder="e.g. Main Cashier" required></div><div class="form-group"><label>Account Type</label><select name="account_type"><option>Cash</option><option>Card</option><option>Bank</option><option>Online</option><option>Other</option></select></div><div class="form-group"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div><div class="form-group" style="flex:0"><button class="btn btn-accent" name="add_account" value="1">Add Account</button></div></form></div>
<div class="card" style="padding:0;overflow-x:auto"><table><thead><tr><th>Account</th><th>Type</th><th>Opening</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr><td><?=h($a['account_name'])?></td><td><?=h($a['account_type'])?></td><td><?=money($a['opening_balance'])?></td><td><?= $a['active'] ? '<span class="badge badge-confirmed">Active</span>' : '<span class="badge badge-cancelled">Inactive</span>' ?></td><td class="actions"><form method="post" style="display:inline"><input type="hidden" name="id" value="<?=$a['id']?>"><input type="hidden" name="active" value="<?=$a['active']?>"><button class="btn btn-sm"><?= $a['active'] ? 'Disable' : 'Enable' ?></button><input type="hidden" name="toggle" value="1"></form> <a class="btn btn-sm btn-danger" href="?delete=<?=$a['id']?>" onclick="return confirm('Delete this POS account?');">Delete</a></td></tr><?php endforeach;?></tbody></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
