<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'POS Terminal';
$error = '';

// Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $cartJson = $_POST['cart_json'] ?? '[]';
    $cart = json_decode($cartJson, true);

    if (!is_array($cart) || !$cart) {
        $error = 'Cart is empty.';
    } else {
        $discount = (float) ($_POST['discount'] ?? 0);
        $taxPercent = (float) ($_POST['tax_percent'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $tendered = (float) ($_POST['amount_tendered'] ?? 0);
        $customerName = trim($_POST['customer_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $cashier = currentUser()['username'] ?? '';
        $posAccountId = (int) ($_POST['pos_account_id'] ?? 0);
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $bookingId = $bookingId > 0 ? $bookingId : null;

        // When a hotel invoice is selected, use its guest as the POS customer.
        if ($bookingId) {
            $bst = $pdo->prepare("SELECT customer_name FROM bookings WHERE id = ? AND status <> 'Cancelled'");
            $bst->execute([$bookingId]);
            $bookingGuest = $bst->fetchColumn();
            if ($bookingGuest !== false && $customerName === '') $customerName = (string)$bookingGuest;
        }

        $result = posCreateSale($pdo, $cart, $discount, $taxPercent, $paymentMethod, $tendered, $customerName, $notes, $cashier, $posAccountId > 0 ? $posAccountId : null, $bookingId);

        if ($result['ok']) {
            header('Location: pos_receipt.php?id=' . $result['sale_id']);
            exit;
        }
        $error = $result['error'];
    }
}

$q = trim($_GET['q'] ?? '');
$products = posProductList($pdo, $q);
$defaultTax = getSetting($pdo, 'pos_tax_percent', '0');
$posAccounts = posAccountList($pdo);
$bookingOptions = posBookingOptions($pdo, trim($_GET['booking_q'] ?? ''));

require __DIR__ . '/includes/header.php';
?>

<div class="flex-between no-print">
    <h1>POS Terminal</h1>
    <a href="pos_sales.php" class="btn btn-secondary">Sales History</a>
    <a href="pos_accounts.php" class="btn btn-secondary">POS Accounts</a>
    <a href="pos_products.php" class="btn btn-secondary">Manage Products</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<div class="pos-layout">
    <div class="pos-products card">
        <form method="get" class="search-box" style="margin-bottom:12px;">
            <input type="text" name="q" placeholder="Search product, SKU, or category..." value="<?= h($q) ?>" style="max-width:320px;display:inline-block;">
            <button class="btn btn-sm" type="submit">Search</button>
            <?php if ($q !== ''): ?><a class="btn btn-sm btn-secondary" href="pos.php">Clear</a><?php endif; ?>
        </form>

        <div class="pos-grid">
        <?php if (!$products): ?>
            <p style="color:var(--muted);">No products found. <a href="pos_products.php">Add some here.</a></p>
        <?php endif; ?>
        <?php foreach ($products as $p): ?>
            <button type="button" class="pos-product-btn"
                data-id="<?= (int) $p['id'] ?>"
                data-name="<?= h($p['name']) ?>"
                data-price="<?= h($p['price']) ?>"
                data-stock="<?= (int) $p['stock_qty'] ?>"
                onclick="addToCart(this)"
                <?= $p['stock_qty'] <= 0 ? 'disabled' : '' ?>>
                <div class="pos-product-name"><?= h($p['name']) ?></div>
                <div class="pos-product-price"><?= money($p['price']) ?></div>
                <div class="pos-product-stock"><?= $p['stock_qty'] > 0 ? (int) $p['stock_qty'] . ' in stock' : 'Out of stock' ?></div>
            </button>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="pos-cart card">
        <h3 style="margin-top:0;">Cart</h3>
        <table id="cart-table">
            <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th></th></tr></thead>
            <tbody id="cart-body">
                <tr id="cart-empty-row"><td colspan="4" style="text-align:center;color:var(--muted);">Cart is empty</td></tr>
            </tbody>
        </table>

        <form method="post" id="checkout-form">
            <input type="hidden" name="checkout" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <div class="form-group">
                <label>Hotel Invoice / Booking No. <span style="color:var(--muted);">(optional)</span></label>
                <select name="booking_id" id="booking_id" onchange="syncBookingCustomer(this)">
                    <option value="0">— Walk-in / Separate POS Bill —</option>
                    <?php foreach ($bookingOptions as $bo): ?>
                        <option value="<?= (int)$bo['id'] ?>" data-customer="<?= h($bo['customer_name']) ?>">
                            <?= h($bo['booking_no']) ?> — <?= h($bo['customer_name']) ?><?= $bo['room_no'] ? ' — Room '.h($bo['room_no']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted);">Select the guest invoice to add this POS sale to the hotel invoice.</small>
            </div>
            <div class="form-group">
                <label>Customer Name (optional)</label>
                <input type="text" name="customer_name" id="customer_name" placeholder="Walk-in">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Discount</label>
                    <input type="number" step="0.01" min="0" name="discount" id="discount" value="0" oninput="recalc()">
                </div>
                <div class="form-group">
                    <label>Tax (%)</label>
                    <input type="number" step="0.01" min="0" name="tax_percent" id="tax_percent" value="<?= h($defaultTax) ?>" oninput="recalc()">
                </div>
            </div>

            <table class="bill-table">
                <tr><td>Subtotal</td><td id="disp-subtotal">0.00</td></tr>
                <tr><td>Discount</td><td id="disp-discount">-0.00</td></tr>
                <tr><td>Tax</td><td id="disp-tax">0.00</td></tr>
                <tr class="bill-total-row"><td>Total</td><td id="disp-total">0.00</td></tr>
            </table>

            <div class="form-row">
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="Online">Online</option>
                        <option value="Room Charge">Room Charge</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount Tendered</label>
                    <input type="number" step="0.01" min="0" name="amount_tendered" id="amount_tendered" value="0" oninput="recalc()">
                </div>
            </div>
            <div class="form-group">
                <label>Change Due</label>
                <div id="disp-change" style="font-weight:700;color:var(--accent);font-size:1.1rem;">0.00</div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <input type="text" name="notes" placeholder="Optional">
            </div>

            <button type="button" class="btn btn-secondary" onclick="clearCart()" style="width:100%;margin-bottom:8px;">Clear Cart</button>
            <button type="submit" class="btn btn-accent" id="checkout-btn" style="width:100%;" disabled>Complete Sale</button>
        </form>
    </div>
</div>

<style>
.pos-layout { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
.pos-products { flex: 2; min-width: 320px; }
.pos-cart { flex: 1; min-width: 300px; position: sticky; top: 16px; }
.pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
.pos-product-btn {
    text-align: left; background: #fff; border: 1px solid var(--border); border-radius: 6px;
    padding: 10px; cursor: pointer; font-family: inherit;
}
.pos-product-btn:hover:not(:disabled) { border-color: var(--primary); background: #f4f9fc; }
.pos-product-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pos-product-name { font-weight: 600; font-size: 0.88rem; margin-bottom: 4px; }
.pos-product-price { color: var(--primary-dark); font-weight: 700; }
.pos-product-stock { font-size: 0.75rem; color: var(--muted); }
#cart-table td, #cart-table th { padding: 6px 8px; font-size: 0.85rem; }
.qty-btn { background: #eef3f7; border: 1px solid var(--border); border-radius: 4px; width: 22px; height: 22px; cursor: pointer; }
</style>

<script>
let cart = [];

function syncBookingCustomer(sel) {
    const opt = sel.options[sel.selectedIndex];
    const customer = opt && opt.dataset.customer ? opt.dataset.customer : '';
    const input = document.getElementById('customer_name');
    if (customer) input.value = customer;
}

function addToCart(btn) {
    const id = parseInt(btn.dataset.id, 10);
    const name = btn.dataset.name;
    const price = parseFloat(btn.dataset.price);
    const stock = parseInt(btn.dataset.stock, 10);

    const existing = cart.find(i => i.id === id);
    if (existing) {
        if (existing.qty < stock) existing.qty++;
    } else {
        cart.push({ id, name, price, stock, qty: 1 });
    }
    renderCart();
}

function changeQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;
    item.qty += delta;
    if (item.qty > item.stock) item.qty = item.stock;
    if (item.qty <= 0) {
        cart = cart.filter(i => i.id !== id);
    }
    renderCart();
}

function removeItem(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}

function clearCart() {
    cart = [];
    renderCart();
}

function renderCart() {
    const body = document.getElementById('cart-body');
    body.innerHTML = '';
    if (cart.length === 0) {
        body.innerHTML = '<tr id="cart-empty-row"><td colspan="4" style="text-align:center;color:var(--muted);">Cart is empty</td></tr>';
    } else {
        cart.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${item.name}</td>
                <td>
                    <button type="button" class="qty-btn" onclick="changeQty(${item.id}, -1)">-</button>
                    ${item.qty}
                    <button type="button" class="qty-btn" onclick="changeQty(${item.id}, 1)">+</button>
                </td>
                <td>${(item.price * item.qty).toFixed(2)}</td>
                <td><button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${item.id})">x</button></td>
            `;
            body.appendChild(tr);
        });
    }
    document.getElementById('checkout-btn').disabled = cart.length === 0;
    recalc();
}

function recalc() {
    const subtotal = cart.reduce((sum, i) => sum + i.price * i.qty, 0);
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const taxPercent = parseFloat(document.getElementById('tax_percent').value) || 0;
    const taxable = Math.max(0, subtotal - discount);
    const tax = taxable * (taxPercent / 100);
    const total = taxable + tax;
    const tendered = parseFloat(document.getElementById('amount_tendered').value) || 0;
    const change = Math.max(0, tendered - total);

    document.getElementById('disp-subtotal').textContent = subtotal.toFixed(2);
    document.getElementById('disp-discount').textContent = '-' + discount.toFixed(2);
    document.getElementById('disp-tax').textContent = tax.toFixed(2);
    document.getElementById('disp-total').textContent = total.toFixed(2);
    document.getElementById('disp-change').textContent = change.toFixed(2);

    document.getElementById('cart_json').value = JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty })));
}

document.getElementById('checkout-form').addEventListener('submit', function () {
    document.getElementById('cart_json').value = JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty })));
});

renderCart();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
