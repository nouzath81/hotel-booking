
require('dotenv').config();
const express=require('express');
const session=require('express-session');
const bcrypt=require('bcryptjs');
const {Pool}=require('pg');
const path=require('path');
const fs=require('fs');
const app=express();
const PORT=process.env.PORT||3000;
if(!process.env.DATABASE_URL) console.warn('DATABASE_URL is not set. Copy .env.example to .env and configure PostgreSQL.');
const pool=new Pool({connectionString:process.env.DATABASE_URL, ssl: process.env.DATABASE_URL && /neon|supabase|render\.com/i.test(process.env.DATABASE_URL) ? {rejectUnauthorized:false}:undefined});
app.set('view engine','ejs'); app.set('views',path.join(__dirname,'views'));
app.use(express.urlencoded({extended:true})); app.use(express.json()); app.use(express.static(path.join(__dirname,'public')));
app.use(session({secret:process.env.SESSION_SECRET||'dev-secret',resave:false,saveUninitialized:false,cookie:{httpOnly:true,maxAge:86400000}}));
app.locals.money=v=>Number(v||0).toFixed(2);
app.use((req,res,next)=>{res.locals.user=req.session.user||null; next();});
async function q(sql,params=[]){return (await pool.query(sql,params)).rows}
async function setup(){
 const sql=fs.readFileSync(path.join(__dirname,'schema.sql'),'utf8'); await pool.query(sql);
 const settings=[['company_name','Your Hotel Name'],['address','Main Street'],['phone',''],['email',''],['website',''],['footer_note','Thank you for your business!'],['pos_tax_percent','0']];
 for(const [k,v] of settings) await pool.query(`INSERT INTO settings(setting_key,setting_value) VALUES($1,$2) ON CONFLICT DO NOTHING`,[k,v]);
 if(!(await q('SELECT 1 FROM room_types LIMIT 1')).length) await pool.query(`INSERT INTO room_types(type_name,total_rooms,default_rate) VALUES ('Standard',10,50),('Deluxe',6,80),('Suite',3,150)`);
 if(!(await q('SELECT 1 FROM pos_products LIMIT 1')).length) await pool.query(`INSERT INTO pos_products(name,sku,category,price,stock_qty) VALUES ('Bottled Water','BEV-001','Beverages',1.50,100),('Soft Drink','BEV-002','Beverages',2,80),('Snack Pack','SNK-001','Snacks',3.50,50),('Laundry Service','SVC-001','Services',8,999),('Room Service Breakfast','SVC-002','Services',12,999)`);
 if(!(await q('SELECT 1 FROM pos_accounts LIMIT 1')).length) await pool.query(`INSERT INTO pos_accounts(account_name,account_type) VALUES ('Cash','Cash'),('Card','Card'),('Bank','Bank'),('Online','Online')`);
 const u=await q('SELECT 1 FROM users WHERE username=$1',['admin']);
 if(!u.length) await pool.query('INSERT INTO users(username,password_hash,role) VALUES($1,$2,$3)', ['admin',await bcrypt.hash('admin123',10),'admin']);
}
function login(req,res,next){if(!req.session.user)return res.redirect('/login?next='+encodeURIComponent(req.originalUrl));next()}
function admin(req,res,next){if(!req.session.user)return res.redirect('/login'); if(req.session.user.role!=='admin')return res.status(403).send('Admin access required'); next()}
async function settings(){const rows=await q('SELECT setting_key,setting_value FROM settings'); return Object.fromEntries(rows.map(x=>[x.setting_key,x.setting_value]));}
function layoutData(extra={}){return extra}

app.get('/login',(req,res)=>res.render('page',{title:'Login',content:'login',next:req.query.next||'',...layoutData()}));
app.post('/login',async(req,res)=>{const u=(await q('SELECT * FROM users WHERE username=$1',[req.body.username]))[0]; if(u&&await bcrypt.compare(req.body.password,u.password_hash)){req.session.user={id:u.id,username:u.username,role:u.role}; return res.redirect(req.body.next||'/')} res.render('page',{title:'Login',content:'login',next:req.body.next||'',error:'Invalid username or password',...layoutData()})});
app.get('/logout',(req,res)=>req.session.destroy(()=>res.redirect('/login')));

app.get('/',login,async(req,res)=>{const [b,p,c]=await Promise.all([q("SELECT COUNT(*) n,COALESCE(SUM((nights*rate_per_night)+extra_charges-discount),0) revenue,COALESCE(SUM(advance_paid),0) advance FROM bookings WHERE status<>'Cancelled'"),q('SELECT COUNT(*) n FROM bookings WHERE checkout_date>=CURRENT_DATE AND checkin_date<=CURRENT_DATE AND status<>\'Cancelled\''),q("SELECT COALESCE(SUM(CASE WHEN type='In' THEN amount ELSE -amount END),0) cash FROM petty_cash")]);res.render('page',{title:'Dashboard',content:'dashboard',stats:{bookings:b[0],occupied:p[0],cash:c[0]},...layoutData()})});

app.get('/bookings',login,async(req,res)=>{const rows=await q('SELECT * FROM bookings ORDER BY id DESC');res.render('page',{title:'Bookings',content:'bookings',rows,...layoutData()})});
app.get('/bookings/new',login,async(req,res)=>{const types=await q('SELECT * FROM room_types ORDER BY type_name');res.render('page',{title:'New Booking',content:'booking-form',types,...layoutData()})});
app.post('/bookings',login,async(req,res)=>{const b=req.body; const nights=Math.max(1,Math.ceil((new Date(b.checkout_date)-new Date(b.checkin_date))/86400000)); const no='BK-'+Date.now(); await q(`INSERT INTO bookings(booking_no,customer_name,phone,email,address,room_type,room_no,room_count,checkin_date,checkout_date,nights,rate_per_night,extra_charges,discount,tax_percent,advance_paid,status,notes) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18)`,[no,b.customer_name,b.phone,b.email,b.address,b.room_type,b.room_no,b.room_count||1,b.checkin_date,b.checkout_date,nights,b.rate_per_night||0,b.extra_charges||0,b.discount||0,b.tax_percent||0,b.advance_paid||0,b.status||'Confirmed',b.notes]);res.redirect('/bookings')});
app.post('/bookings/:id/delete',admin,async(req,res)=>{await q('DELETE FROM bookings WHERE id=$1',[req.params.id]);res.redirect('/bookings')});

app.get('/rooms',login,async(req,res)=>{const [types,rooms]=await Promise.all([q('SELECT * FROM room_types ORDER BY type_name'),q('SELECT * FROM hotel_rooms ORDER BY room_no')]);res.render('page',{title:'Rooms',content:'rooms',types,rooms,...layoutData()})});
app.post('/room-types',admin,async(req,res)=>{await q('INSERT INTO room_types(type_name,total_rooms,default_rate) VALUES($1,$2,$3) ON CONFLICT(type_name) DO UPDATE SET total_rooms=EXCLUDED.total_rooms,default_rate=EXCLUDED.default_rate',[req.body.type_name,req.body.total_rooms||0,req.body.default_rate||0]);res.redirect('/rooms')});
app.post('/rooms',admin,async(req,res)=>{await q('INSERT INTO hotel_rooms(room_no,room_type,status) VALUES($1,$2,$3) ON CONFLICT(room_no) DO UPDATE SET room_type=EXCLUDED.room_type,status=EXCLUDED.status',[req.body.room_no,req.body.room_type,req.body.status||'Active']);res.redirect('/rooms')});

app.get('/payments',login,async(req,res)=>{const rows=await q(`SELECT p.*,b.booking_no,b.customer_name FROM payments p JOIN bookings b ON b.id=p.booking_id ORDER BY p.id DESC`);const bookings=await q('SELECT id,booking_no,customer_name FROM bookings ORDER BY id DESC');res.render('page',{title:'Payments',content:'payments',rows,bookings,...layoutData()})});
app.post('/payments',login,async(req,res)=>{await q('INSERT INTO payments(booking_id,payment_date,amount,method,notes) VALUES($1,$2,$3,$4,$5)',[req.body.booking_id,req.body.payment_date||new Date().toISOString().slice(0,10),req.body.amount,req.body.method,req.body.notes]);res.redirect('/payments')});

app.get('/petty-cash',login,async(req,res)=>{const rows=await q('SELECT * FROM petty_cash ORDER BY entry_date DESC,id DESC');res.render('page',{title:'Petty Cash',content:'petty-cash',rows,...layoutData()})});
app.post('/petty-cash',login,async(req,res)=>{await q('INSERT INTO petty_cash(entry_date,description,category,type,amount) VALUES($1,$2,$3,$4,$5)',[req.body.entry_date||new Date().toISOString().slice(0,10),req.body.description,req.body.category,req.body.type,req.body.amount]);res.redirect('/petty-cash')});

app.get('/pos',login,async(req,res)=>{const products=await q('SELECT * FROM pos_products WHERE active=1 ORDER BY name');const sales=await q('SELECT * FROM pos_sales ORDER BY id DESC LIMIT 30');res.render('page',{title:'POS',content:'pos',products,sales,...layoutData()})});
app.post('/pos/sale',login,async(req,res)=>{const items=JSON.parse(req.body.items||'[]');let subtotal=0; for(const i of items) subtotal+=Number(i.unit_price)*Number(i.qty); const discount=Number(req.body.discount||0), taxP=Number(req.body.tax_percent||0), tax=Math.max(0,(subtotal-discount))*taxP/100,total=subtotal-discount+tax,tender=Number(req.body.amount_tendered||0); const sale=(await q(`INSERT INTO pos_sales(sale_no,customer_name,subtotal,discount,tax_percent,tax_amount,total,payment_method,amount_tendered,change_due,cashier,notes) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING id`,['POS-'+Date.now(),req.body.customer_name,subtotal,discount,taxP,tax,total,req.body.payment_method||'Cash',tender,Math.max(0,tender-total),req.session.user.username,req.body.notes]))[0]; for(const i of items){await q('INSERT INTO pos_sale_items(sale_id,product_id,product_name,unit_price,qty,line_total) VALUES($1,$2,$3,$4,$5,$6)',[sale.id,i.product_id,i.name,i.unit_price,i.qty,Number(i.unit_price)*Number(i.qty)]); if(i.product_id) await q('UPDATE pos_products SET stock_qty=GREATEST(0,stock_qty-$1) WHERE id=$2',[i.qty,i.product_id]);} res.redirect('/pos')});
app.get('/pos/products',login,async(req,res)=>{const rows=await q('SELECT * FROM pos_products ORDER BY id DESC');res.render('page',{title:'POS Products',content:'products',rows,...layoutData()})});
app.post('/pos/products',admin,async(req,res)=>{await q('INSERT INTO pos_products(name,sku,category,price,stock_qty) VALUES($1,$2,$3,$4,$5)',[req.body.name,req.body.sku,req.body.category,req.body.price,req.body.stock_qty||0]);res.redirect('/pos/products')});

app.get('/settings',admin,async(req,res)=>res.render('page',{title:'Settings',content:'settings',s:await settings(),...layoutData()}));
app.post('/settings',admin,async(req,res)=>{for(const k of ['company_name','address','phone','email','website','footer_note','pos_tax_percent']) if(req.body[k]!==undefined) await q('INSERT INTO settings(setting_key,setting_value) VALUES($1,$2) ON CONFLICT(setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value',[k,req.body[k]]);res.redirect('/settings')});
app.post('/users',admin,async(req,res)=>{await q('INSERT INTO users(username,password_hash,role) VALUES($1,$2,$3)',[req.body.username,await bcrypt.hash(req.body.password,10),req.body.role||'staff']);res.redirect('/settings')});

app.get('/reports',login,async(req,res)=>{const bookings=await q(`SELECT room_type,status,COUNT(*) count,COALESCE(SUM(nights*rate_per_night+extra_charges-discount),0) revenue FROM bookings GROUP BY room_type,status ORDER BY room_type`);const cash=await q(`SELECT type,COALESCE(SUM(amount),0) amount FROM petty_cash GROUP BY type`);res.render('page',{title:'Reports',content:'reports',bookings,cash,...layoutData()})});
app.get('/api/availability',async(req,res)=>{const type=req.query.room_type;const rooms=await q('SELECT room_no FROM hotel_rooms WHERE room_type=$1 AND status=$2 ORDER BY room_no',[type,'Active']);const booked=await q(`SELECT room_no FROM bookings WHERE room_type=$1 AND status<>'Cancelled' AND checkin_date < $3 AND checkout_date > $2`,[type,req.query.checkin,req.query.checkout]);const used=new Set(booked.flatMap(x=>String(x.room_no||'').split(',').map(s=>s.trim())));res.json({available:rooms.map(x=>x.room_no).filter(x=>!used.has(x))})});
app.get('/health',async(req,res)=>{try{await q('SELECT 1');res.json({ok:true,database:'postgresql'})}catch(e){res.status(500).json({ok:false,error:e.message})}});

app.use((err,req,res,next)=>{console.error(err); if(res.headersSent) return next(err); res.status(500).render('page',{title:'Server Error',content:'login',error:'A server error occurred. Check the terminal for details.',...layoutData()});});
setup().then(()=>app.listen(PORT,()=>console.log(`Hotel Booking System running on http://localhost:${PORT}`))).catch(e=>{console.error(e);process.exit(1)});
