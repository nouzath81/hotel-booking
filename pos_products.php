<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'POS Products';
$errors = [];

// Add product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $stock = (int) ($_POST['stock_qty'] ?? 0);

    if ($name === '') $errors[] = 'Product name is required.';
    if ($price < 0) $errors[] = 'Price cannot be negative.';
    if ($stock < 0) $errors[] = 'Stock cannot be negative.';

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO pos_products (name, sku, category, price, stock_qty) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $sku, $category, $price, $stock]);
        header('Location: pos_products.php?added=1');
        exit;
    }
}

// Update product (price / stock / category / active)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $id = (int) $_POST['id'];
    $price = (float) $_POST['price'];
    $stock = (int) $_POST['stock_qty'];
    $category = trim($_POST['category'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;

    $stmt = $pdo->prepare('UPDATE pos_products SET price = ?, stock_qty = ?, category = ?, active = ? WHERE id = ?');
    $stmt->execute([$price, $stock, $category, $active, $id]);
    header('Location: pos_products.php?updated=1');
    exit;
}

// Delete product
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM pos_products WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    header('Location: pos_products.php?deleted=1');
    exit;
}

$products = posProductListAll($pdo);

require __DIR__ . '/includes/header.php';
?>

<div class="flex-between">
    <h1>POS Products</h1>
    <a href="pos.php" class="btn btn-accent">&larr; Back to Terminal</a>
</div>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Product added.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Product updated.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Product removed.</div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
<table>
    <thead>
        <tr><th>Name</th><th>SKU</th><th>Category</th><th>Price</th><th>Stock</th><th>Active</th><th>Manage</th></tr>
    </thead>
    <tbody>
    <?php if (!$products): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px;">No products yet — add one below.</td></tr>
    <?php endif; ?>
    <?php foreach ($products as $p): ?>
        <tr>
            <td><?= h($p['name']) ?></td>
            <td><?= h($p['sku']) ?: '—' ?></td>
            <td><?= h($p['category']) ?: '—' ?></td>
            <td><?= money($p['price']) ?></td>
            <td><?= (int) $p['stock_qty'] ?><?= $p['stock_qty'] <= 5 ? ' <span class="badge badge-checkedin">Low</span>' : '' ?></td>
            <td><?= $p['active'] ? '<span class="badge badge-confirmed">Yes</span>' : '<span class="badge badge-cancelled">No</span>' ?></td>
            <td class="actions">
                <button type="button" class="btn btn-sm" onclick="toggleEdit(<?= $p['id'] ?>)">Edit</button>
                <a class="btn btn-sm btn-danger" href="pos_products.php?delete=<?= $p['id'] ?>"
                   onclick="return confirm('Delete this product? This does not affect past sales.');">Delete</a>
            </td>
        </tr>
        <tr id="edit-row-<?= $p['id'] ?>" style="display:none;">
            <td colspan="7">
                <form method="post" class="form-row" style="align-items:flex-end;margin:0;">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" value="<?= h($p['category']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Price</label>
                        <input type="number" step="0.01" min="0" name="price" value="<?= h($p['price']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Stock Qty</label>
                        <input type="number" min="0" name="stock_qty" value="<?= (int) $p['stock_qty'] ?>">
                    </div>
                    <div class="form-group" style="flex:0;">
                        <label>Active</label>
                        <input type="checkbox" name="active" <?= $p['active'] ? 'checked' : '' ?> style="width:auto;">
                    </div>
                    <div class="form-group" style="flex:0;">
                        <button type="submit" name="update_product" value="1" class="btn btn-accent btn-sm">Save</button>
                    </div>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="card">
    <h3>Add Product</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" required>
        </div>
        <div class="form-group">
            <label>SKU</label>
            <input type="text" name="sku" placeholder="Optional">
        </div>
        <div class="form-group">
            <label>Category</label>
            <input type="text" name="category" placeholder="e.g. Beverages, Snacks, Services">
        </div>
        <div class="form-group">
            <label>Price</label>
            <input type="number" step="0.01" min="0" name="price" value="0" required>
        </div>
        <div class="form-group">
            <label>Stock Qty</label>
            <input type="number" min="0" name="stock_qty" value="0" required>
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" name="add_product" value="1" class="btn btn-accent">Add</button>
        </div>
    </form>
</div>

<script>
function toggleEdit(id) {
    const row = document.getElementById('edit-row-' + id);
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
