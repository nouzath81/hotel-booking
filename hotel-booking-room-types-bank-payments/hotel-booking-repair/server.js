
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
async function syncRoomTypeCounts(){
  await q(`UPDATE room_types rt SET total_rooms=COALESCE((SELECT COUNT(*) FROM hotel_rooms hr WHERE hr.room_type=rt.type_name AND hr.status<>'Inactive'),0)`);
}
const SRI_LANKAN_BANKS=[
'Amana Bank PLC','Bank of Ceylon','Bank of China Limited','Cargills Bank PLC','Citibank, N.A.','Commercial Bank of Ceylon PLC','Deutsche Bank AG, Colombo Branch','DFCC Bank PLC','Habib Bank Ltd','Hatton National Bank PLC','Indian Bank','Indian Overseas Bank','MCB Bank Ltd','National Development Bank PLC','Nations Trust Bank PLC','Pan Asia Banking Corporation PLC','People’s Bank','Public Bank Berhad','Sampath Bank PLC','Seylan Bank PLC','Standard Chartered Bank','State Bank of India','The Hongkong & Shanghai Banking Corporation Ltd (HSBC)','Union Bank of Colombo PLC',
'Housing Development Finance Corporation Bank of Sri Lanka (HDFC)','National Savings Bank','Pradeshiya Sanwardhana Bank','SANASA Development Bank PLC','Sri Lanka Savings Bank Ltd','State Mortgage and Investment Bank'
];
async function setup(){
 const sql=fs.readFileSync(path.join(__dirname,'schema.sql'),'utf8'); await pool.query(sql);
 await pool.query(`ALTER TABLE bookings ADD COLUMN IF NOT EXISTS whatsapp_no VARCHAR(32)`);
 await pool.query(`CREATE TABLE IF NOT EXISTS bank_accounts (
   id SERIAL PRIMARY KEY,
   bank_name VARCHAR(200) NOT NULL,
   account_name VARCHAR(200),
   account_no VARCHAR(100) NOT NULL,
   branch VARCHAR(150),
   account_type VARCHAR(50) DEFAULT 'Bank',
   active INTEGER NOT NULL DEFAULT 1,
   created_at TIMESTAMPTZ DEFAULT NOW(),
   UNIQUE(bank_name,account_no)
 )`);
 await pool.query(`ALTER TABLE payments ADD COLUMN IF NOT EXISTS bank_account_id INTEGER REFERENCES bank_accounts(id) ON DELETE SET NULL`);
 await pool.query(`ALTER TABLE payments ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100)`);
 await pool.query(`UPDATE bookings SET whatsapp_no=phone WHERE (whatsapp_no IS NULL OR whatsapp_no='') AND phone IS NOT NULL AND phone<>''`);
 const settings=[['company_name','Your Hotel Name'],['address','Main Street'],['phone',''],['email',''],['website',''],['footer_note','Thank you for your business!'],['pos_tax_percent','0']];
 for(const [k,v] of settings) await pool.query(`INSERT INTO settings(setting_key,setting_value) VALUES($1,$2) ON CONFLICT DO NOTHING`,[k,v]);
 if(!(await q('SELECT 1 FROM room_types LIMIT 1')).length) await pool.query(`INSERT INTO room_types(type_name,total_rooms,default_rate) VALUES ('Standard',10,50),('Deluxe',6,80),('Suite',3,150)`);
 await q(`CREATE INDEX IF NOT EXISTS idx_hotel_rooms_type_status ON hotel_rooms(room_type,status)`);
 await syncRoomTypeCounts();
 if(!(await q('SELECT 1 FROM pos_products LIMIT 1')).length) await pool.query(`INSERT INTO pos_products(name,sku,category,price,stock_qty) VALUES ('Bottled Water','BEV-001','Beverages',1.50,100),('Soft Drink','BEV-002','Beverages',2,80),('Snack Pack','SNK-001','Snacks',3.50,50),('Laundry Service','SVC-001','Services',8,999),('Room Service Breakfast','SVC-002','Services',12,999)`);
 if(!(await q('SELECT 1 FROM pos_accounts LIMIT 1')).length) await pool.query(`INSERT INTO pos_accounts(account_name,account_type) VALUES ('Cash','Cash'),('Card','Card'),('Bank','Bank'),('Online','Online')`);
 const u=await q('SELECT 1 FROM users WHERE username=$1',['admin']);
 if(!u.length) await pool.query('INSERT INTO users(username,password_hash,role) VALUES($1,$2,$3)', ['admin',await bcrypt.hash('admin123',10),'admin']);
}
function login(req,res,next){if(!req.session.user)return res.redirect('/login?next='+encodeURIComponent(req.originalUrl));next()}
function admin(req,res,next){if(!req.session.user)return res.redirect('/login'); if(req.session.user.role!=='admin')return res.status(403).send('Admin access required'); next()}
async function settings(){const rows=await q('SELECT setting_key,setting_value FROM settings'); return Object.fromEntries(rows.map(x=>[x.setting_key,x.setting_value]));}
function normalizeWhatsApp(phone){let s=String(phone||'').replace(/\D/g,''); if(!s)return ''; if(s.startsWith('00'))s=s.slice(2); if(s.startsWith('0'))s='94'+s.slice(1); if(s.startsWith('94'))return s; return s;}

async function sendWhatsAppText(to,text){
  const token=process.env.WHATSAPP_ACCESS_TOKEN;
  const phoneNumberId=process.env.WHATSAPP_PHONE_NUMBER_ID;
  if(!token||!phoneNumberId||!to) return {sent:false,reason:'WhatsApp Cloud API is not configured'};
  const version=process.env.WHATSAPP_API_VERSION||'v23.0';
  const recipient=normalizeWhatsApp(to);
  if(!recipient) return {sent:false,reason:'Invalid recipient number'};
  try{
    const r=await fetch(`https://graph.facebook.com/${version}/${phoneNumberId}/messages`,{
      method:'POST',
      headers:{'Authorization':`Bearer ${token}`,'Content-Type':'application/json'},
      body:JSON.stringify({messaging_product:'whatsapp',to:recipient,type:'text',text:{preview_url:false,body:text}})
    });
    const data=await r.json().catch(()=>({}));
    if(!r.ok) return {sent:false,reason:data?.error?.message||`HTTP ${r.status}`};
    return {sent:true};
  }catch(e){return {sent:false,reason:e.message};}
}

async function notifyBookingByWhatsApp(bookingId){
  try{
    const b=(await q(`SELECT booking_no,customer_name,phone,room_type,room_no,room_count,checkin_date,checkout_date,nights,rate_per_night,extra_charges,discount,tax_percent,advance_paid,status,(((nights*rate_per_night)+extra_charges-discount)*(1+tax_percent/100)) AS total FROM bookings WHERE id=$1`,[bookingId]))[0];
    if(!b) return;
    const rt=(await q('SELECT total_rooms FROM room_types WHERE type_name=$1',[b.room_type]))[0];
    const totalRooms=Number(rt?.total_rooms||0);
    const occ=(await q(`SELECT COALESCE(SUM(room_count),0) n FROM bookings WHERE room_type=$1 AND status NOT IN ('Cancelled','Checked-Out') AND checkin_date < $3 AND checkout_date > $2`,[b.room_type,b.checkin_date,b.checkout_date]))[0];
    const remaining=Math.max(0,totalRooms-Number(occ?.n||0));
    const msg=[
      '🏨 NEW HOTEL BOOKING',
      `Booking No: ${b.booking_no}`,
      `Customer: ${b.customer_name||'-'}`,
      `Phone: ${b.phone||'-'}`,
      `Room Type: ${b.room_type||'-'}`,
      `Booked Room: ${b.room_count||1}`,
      `Room No: ${b.room_no||'Not assigned'}`,
      `Check-in: ${b.checkin_date}`,
      `Check-out: ${b.checkout_date}`,
      `Nights: ${b.nights}`,
      `Rate/Night: LKR ${Number(b.rate_per_night||0).toFixed(2)}`,
      `Total: LKR ${Number(b.total||0).toFixed(2)}`,
      `Advance Paid: LKR ${Number(b.advance_paid||0).toFixed(2)}`,
      `Balance: LKR ${Math.max(0,Number(b.total||0)-Number(b.advance_paid||0)).toFixed(2)}`,
      `Status: ${b.status||'-'}`,
      `Remaining ${b.room_type} Rooms: ${remaining}`,
      `Remaining All Rooms: ${Math.max(0,totalRooms-Number(occ?.n||0))}`
    ].join('\n');
    const recipients=[['Owner',process.env.WHATSAPP_OWNER],['Manager',process.env.WHATSAPP_MANAGER]].filter(([,n])=>n);
    if(!recipients.length){console.warn('Booking WhatsApp notification not sent: set WHATSAPP_OWNER and/or WHATSAPP_MANAGER');return;}
    for(const [label,number] of recipients){const result=await sendWhatsAppText(number,msg); if(!result.sent) console.warn(`WhatsApp ${label} notification failed: ${result.reason}`);}
  }catch(e){console.warn('Booking WhatsApp notification failed:',e.message);}
}
function layoutData(extra={}){return extra}

app.get('/login',(req,res)=>res.render('page',{title:'Login',content:'login',next:req.query.next||'',...layoutData()}));
app.post('/login',async(req,res)=>{const u=(await q('SELECT * FROM users WHERE username=$1',[req.body.username]))[0]; if(u&&await bcrypt.compare(req.body.password,u.password_hash)){req.session.user={id:u.id,username:u.username,role:u.role}; return res.redirect(req.body.next||'/')} res.render('page',{title:'Login',content:'login',next:req.body.next||'',error:'Invalid username or password',...layoutData()})});
app.get('/logout',(req,res)=>req.session.destroy(()=>res.redirect('/login')));

app.get('/',login,async(req,res)=>{const [b,p,c,r]=await Promise.all([q("SELECT COUNT(*) n,COALESCE(SUM((nights*rate_per_night)+extra_charges-discount),0) revenue,COALESCE(SUM(advance_paid),0) advance FROM bookings WHERE status<>'Cancelled'"),q("SELECT COALESCE(SUM(room_count),0) n,COUNT(*) booking_count FROM bookings WHERE checkout_date>CURRENT_DATE AND checkin_date<=CURRENT_DATE AND status NOT IN ('Cancelled','Checked-Out')"),q("SELECT COALESCE(SUM(CASE WHEN type='In' THEN amount ELSE -amount END),0) cash FROM petty_cash"),q("SELECT COALESCE(SUM(total_rooms),0) total_rooms FROM room_types")]);const totalRooms=Number(r[0].total_rooms||0);const bookedRooms=Number(p[0].n||0);const remainingRooms=Math.max(0,totalRooms-bookedRooms);res.render('page',{title:'Dashboard',content:'dashboard',stats:{bookings:b[0],occupied:p[0],cash:c[0],rooms:{total:totalRooms,booked:bookedRooms,remaining:remainingRooms}},...layoutData()})});

app.get('/bookings',login,async(req,res)=>{const rows=await q('SELECT * FROM bookings ORDER BY id DESC');res.render('page',{title:'Bookings',content:'bookings',rows,...layoutData()})});
app.get('/api/bookings',login,async(req,res)=>{res.set('Cache-Control','no-store');const rows=await q(`SELECT id,booking_no,customer_name,phone,whatsapp_no,room_type,room_no,room_count,checkin_date,checkout_date,nights,rate_per_night,extra_charges,discount,tax_percent,advance_paid,status,((nights*rate_per_night)+extra_charges-discount) AS subtotal,(((nights*rate_per_night)+extra_charges-discount)*(1+tax_percent/100)) AS total FROM bookings ORDER BY id DESC`);res.json(rows)});
app.get('/api/dashboard',login,async(req,res)=>{res.set('Cache-Control','no-store');const [b,p,r]=await Promise.all([q("SELECT COUNT(*) n,COALESCE(SUM(((nights*rate_per_night)+extra_charges-discount)*(1+tax_percent/100)),0) revenue,COALESCE(SUM(advance_paid),0) advance FROM bookings WHERE status<>'Cancelled'"),q("SELECT COALESCE(SUM(room_count),0) n,COUNT(*) booking_count FROM bookings WHERE checkout_date>CURRENT_DATE AND checkin_date<=CURRENT_DATE AND status NOT IN ('Cancelled','Checked-Out')"),q("SELECT COALESCE(SUM(total_rooms),0) total_rooms FROM room_types")]);const totalRooms=Number(r[0].total_rooms||0);const bookedRooms=Number(p[0].n||0);res.json({bookings:b[0],occupied:p[0],rooms:{total:totalRooms,booked:bookedRooms,remaining:Math.max(0,totalRooms-bookedRooms)}})});
app.get('/bookings/new',login,async(req,res)=>{const types=await q('SELECT * FROM room_types ORDER BY type_name');res.render('page',{title:'New Booking',content:'booking-form',types,...layoutData()})});
app.post('/bookings',login,async(req,res)=>{const b=req.body; if(!b.checkin_date||!b.checkout_date||new Date(b.checkout_date)<=new Date(b.checkin_date)) return res.status(400).send('Check-out date must be after check-in date.'); const nights=Math.max(1,Math.ceil((new Date(b.checkout_date)-new Date(b.checkin_date))/86400000)); const roomList=String(b.room_no||'').split(',').map(x=>x.trim()).filter(Boolean); const roomCount=roomList.length||Math.max(1,Number(b.room_count||1)); if(roomList.length){const assigned=await q("SELECT room_no FROM hotel_rooms WHERE room_type=$1 AND status='Active' AND room_no = ANY($2::text[])",[b.room_type,roomList]);const assignedSet=new Set(assigned.map(x=>x.room_no));const invalid=roomList.filter(x=>!assignedSet.has(x));if(invalid.length)return res.status(400).send(`Room ${invalid.join(', ')} is not assigned to ${b.room_type} or is not active.`);const booked=await q("SELECT room_no FROM bookings WHERE room_type=$1 AND status NOT IN ('Cancelled','Checked-Out') AND checkin_date < $3 AND checkout_date > $2 AND room_no IS NOT NULL",[b.room_type,b.checkin_date,b.checkout_date]);const used=new Set(booked.flatMap(x=>String(x.room_no||'').split(',').map(v=>v.trim()).filter(Boolean)));const conflict=roomList.find(x=>used.has(x));if(conflict)return res.status(409).send(`Room ${conflict} is already booked for the selected dates.`);}else{const rt=(await q('SELECT total_rooms FROM room_types WHERE type_name=$1',[b.room_type]))[0];const total=Number(rt?.total_rooms||0);const booked=await q("SELECT COALESCE(SUM(room_count),0) n FROM bookings WHERE room_type=$1 AND status NOT IN ('Cancelled','Checked-Out') AND checkin_date < $3 AND checkout_date > $2",[b.room_type,b.checkin_date,b.checkout_date]);if(total && Number(booked[0].n)+roomCount>total)return res.status(409).send(`Only ${Math.max(0,total-Number(booked[0].n))} ${b.room_type} room(s) remain for those dates.`);} const no='BK-'+Date.now(); await q(`INSERT INTO bookings(booking_no,customer_name,phone,whatsapp_no,email,address,room_type,room_no,room_count,checkin_date,checkout_date,nights,rate_per_night,extra_charges,discount,tax_percent,advance_paid,status,notes) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19)`,[no,b.customer_name,b.phone,normalizeWhatsApp(b.phone),b.email,b.address,b.room_type,roomList.join(', '),roomCount,b.checkin_date,b.checkout_date,nights,b.rate_per_night||0,b.extra_charges||0,b.discount||0,b.tax_percent||0,b.advance_paid||0,b.status||'Confirmed',b.notes]); const created=(await q('SELECT id FROM bookings WHERE booking_no=$1',[no]))[0]; notifyBookingByWhatsApp(created.id); res.redirect('/bookings')});
app.post('/bookings/:id/delete',admin,async(req,res)=>{await q('DELETE FROM bookings WHERE id=$1',[req.params.id]);res.redirect('/bookings')});

app.get('/rooms',login,async(req,res)=>{
 const [types,rooms]=await Promise.all([
   q(`SELECT rt.*, (SELECT COUNT(*) FROM hotel_rooms hr WHERE hr.room_type=rt.type_name AND hr.status<>'Inactive') AS assigned_rooms, (SELECT COUNT(*) FROM hotel_rooms hr WHERE hr.room_type=rt.type_name AND hr.status='Active') AS active_rooms FROM room_types rt ORDER BY rt.type_name`),
   q(`SELECT hr.*, rt.type_name AS type_label FROM hotel_rooms hr LEFT JOIN room_types rt ON rt.type_name=hr.room_type ORDER BY hr.room_no`)
 ]);
 res.render('page',{title:'Room Management',content:'rooms',types,rooms,...layoutData()})
});
app.post('/room-types',admin,async(req,res)=>{
 const type=String(req.body.type_name||'').trim(); const oldType=String(req.body.old_type_name||'').trim();
 if(!type)return res.status(400).send('Room type is required.');
 const rate=Number(req.body.default_rate||0);
 if(oldType){
   const existing=await q('SELECT id FROM room_types WHERE type_name=$1',[oldType]);
   if(!existing.length)return res.status(404).send('Room type not found.');
   if(type!==oldType && (await q('SELECT 1 FROM room_types WHERE type_name=$1',[type])).length)return res.status(409).send('That room type already exists.');
   await q('UPDATE room_types SET type_name=$1,default_rate=$2 WHERE type_name=$3',[type,rate,oldType]);
   if(type!==oldType){ await q('UPDATE hotel_rooms SET room_type=$1 WHERE room_type=$2',[type,oldType]); await q('UPDATE bookings SET room_type=$1 WHERE room_type=$2',[type,oldType]); }
 }else{
   await q('INSERT INTO room_types(type_name,total_rooms,default_rate) VALUES($1,0,$2)',[type,rate]);
 }
 await syncRoomTypeCounts(); res.redirect('/rooms')
});
app.post('/room-types/:id/delete',admin,async(req,res)=>{
 const rt=(await q('SELECT * FROM room_types WHERE id=$1',[req.params.id]))[0];
 if(!rt)return res.status(404).send('Room type not found.');
 const assigned=Number((await q('SELECT COUNT(*) n FROM hotel_rooms WHERE room_type=$1',[rt.type_name]))[0].n||0);
 const booked=Number((await q("SELECT COUNT(*) n FROM bookings WHERE room_type=$1 AND status NOT IN ('Cancelled','Checked-Out')",[rt.type_name]))[0].n||0);
 if(assigned||booked)return res.status(409).send(`Cannot delete ${rt.type_name}. Remove ${assigned} assigned room(s) and finish/cancel ${booked} active booking(s) first.`);
 await q('DELETE FROM room_types WHERE id=$1',[req.params.id]); res.redirect('/rooms')
});
app.post('/rooms',admin,async(req,res)=>{const roomNo=String(req.body.room_no||'').trim();const roomType=String(req.body.room_type||'').trim();if(!roomNo||!roomType)return res.status(400).send('Room number and room type are required.');await q('INSERT INTO hotel_rooms(room_no,room_type,status) VALUES($1,$2,$3) ON CONFLICT(room_no) DO UPDATE SET room_type=EXCLUDED.room_type,status=EXCLUDED.status',[roomNo,roomType,req.body.status||'Active']);await syncRoomTypeCounts();res.redirect('/rooms')});
app.post('/rooms/:id/delete',admin,async(req,res)=>{await q('DELETE FROM hotel_rooms WHERE id=$1',[req.params.id]);await syncRoomTypeCounts();res.redirect('/rooms')});
app.get('/api/rooms',login,async(req,res)=>{const type=req.query.room_type;const rows=await q(`SELECT room_no,status FROM hotel_rooms WHERE ($1::text IS NULL OR room_type=$1) ORDER BY room_no`,[type||null]);res.set('Cache-Control','no-store');res.json(rows)});

app.get('/payments',login,async(req,res)=>{
 const [rows,bookings,accounts]=await Promise.all([
   q(`SELECT p.*,b.booking_no,b.customer_name,ba.bank_name,ba.account_name,ba.account_no FROM payments p JOIN bookings b ON b.id=p.booking_id LEFT JOIN bank_accounts ba ON ba.id=p.bank_account_id ORDER BY p.id DESC`),
   q('SELECT id,booking_no,customer_name FROM bookings ORDER BY id DESC'),
   q('SELECT * FROM bank_accounts WHERE active=1 ORDER BY bank_name,account_no')
 ]);
 res.render('page',{title:'Payments',content:'payments',rows,bookings,accounts,banks:SRI_LANKAN_BANKS,...layoutData()})
});
app.post('/payments',login,async(req,res)=>{
 const method=String(req.body.method||'Cash');
 const bankAccountId=req.body.bank_account_id?Number(req.body.bank_account_id):null;
 if(['Bank Transfer','Bank Deposit'].includes(method) && !bankAccountId)return res.status(400).send('Please select a bank account for this payment method.');
 await q('INSERT INTO payments(booking_id,payment_date,amount,method,bank_account_id,reference_no,notes) VALUES($1,$2,$3,$4,$5,$6,$7)',[req.body.booking_id,req.body.payment_date||new Date().toISOString().slice(0,10),req.body.amount,method,bankAccountId,req.body.reference_no,req.body.notes]);
 res.redirect('/payments')
});
app.post('/bank-accounts',admin,async(req,res)=>{
 const bank=String(req.body.bank_name||'').trim(), accountNo=String(req.body.account_no||'').trim();
 if(!bank||!accountNo)return res.status(400).send('Bank and account number are required.');
 await q(`INSERT INTO bank_accounts(bank_name,account_name,account_no,branch,account_type) VALUES($1,$2,$3,$4,$5) ON CONFLICT(bank_name,account_no) DO UPDATE SET account_name=EXCLUDED.account_name,branch=EXCLUDED.branch,account_type=EXCLUDED.account_type,active=1`,[bank,req.body.account_name,accountNo,req.body.branch,req.body.account_type||'Bank']);
 res.redirect('/payments')
});
app.post('/bank-accounts/:id/delete',admin,async(req,res)=>{await q('UPDATE bank_accounts SET active=0 WHERE id=$1',[req.params.id]);res.redirect('/payments')});

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
app.get('/api/availability',async(req,res)=>{const type=req.query.room_type;const rooms=await q('SELECT room_no FROM hotel_rooms WHERE room_type=$1 AND status=$2 ORDER BY room_no',[type,'Active']);const booked=await q(`SELECT room_no FROM bookings WHERE room_type=$1 AND status NOT IN ('Cancelled','Checked-Out') AND checkin_date < $3 AND checkout_date > $2`,[type,req.query.checkin,req.query.checkout]);const used=new Set(booked.flatMap(x=>String(x.room_no||'').split(',').map(s=>s.trim()).filter(Boolean)));res.set('Cache-Control','no-store');res.json({available:rooms.map(x=>x.room_no).filter(x=>!used.has(x)),all:rooms.map(x=>x.room_no),booked:[...used]})});
app.get('/health',async(req,res)=>{try{await q('SELECT 1');res.json({ok:true,database:'postgresql'})}catch(e){res.status(500).json({ok:false,error:e.message})}});

app.use((err,req,res,next)=>{console.error(err); if(res.headersSent) return next(err); res.status(500).render('page',{title:'Server Error',content:'login',error:'A server error occurred. Check the terminal for details.',...layoutData()});});
setup().then(()=>app.listen(PORT,()=>console.log(`Hotel Booking System running on http://localhost:${PORT}`))).catch(e=>{console.error(e);process.exit(1)});
