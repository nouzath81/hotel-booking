# Hotel Booking System — Node.js + PostgreSQL

This version is a Node.js/Express conversion of the uploaded hotel booking application. It uses PostgreSQL through the `pg` driver and runs with `npm start`.

## Requirements
- Node.js 18+ (Node 20 or 22 recommended)
- A PostgreSQL database: local PostgreSQL or a cloud provider such as Neon

## Install and run

Open this folder in VS Code, then use **Command Prompt (CMD)** or PowerShell in the project directory:

```bash
npm install
npm start
```

Open:

`http://localhost:3000`

## Database configuration

Copy `.env.example` to `.env` and set `DATABASE_URL`.

### Local PostgreSQL

```env
PORT=3000
DATABASE_URL=postgresql://postgres:YOUR_PASSWORD@localhost:5432/hotel_booking
SESSION_SECRET=replace-with-a-long-random-secret
```

### Neon PostgreSQL

In Neon, open your project and choose **Connect**. Copy the PostgreSQL connection string and put it in `.env`:

```env
DATABASE_URL="postgresql://USER:PASSWORD@YOUR-NEON-HOST/YOUR_DATABASE?sslmode=require"
```

Keep `.env` private. Never commit it to GitHub.

The server automatically creates the application tables and seed data on first successful database connection.

## Default login

Username: `admin`
Password: `admin123`

Change/create users from **Settings** after logging in.

## Useful URLs

- `/` — Dashboard
- `/bookings` — Bookings
- `/bookings/new` — New booking
- `/rooms` — Rooms and room types
- `/payments` — Payments
- `/petty-cash` — Petty cash
- `/pos` — Point of sale
- `/pos/products` — POS products
- `/reports` — Reports
- `/settings` — Company settings and users (admin)
- `/health` — Database health check
- `/api/availability` — Room availability JSON API

## Important

This project uses the `pg` PostgreSQL driver. Prisma is **not required** for this version. If you want Prisma ORM instead, the database layer must be migrated to a Prisma schema/client separately.


## Automatic WhatsApp owner/manager booking notifications
After a new booking is saved, the server can automatically send the full booking details to both the owner and manager using the WhatsApp Cloud API. The message includes customer name/phone, room type, room count, room number, check-in/out, nights, total, advance, balance, status, and remaining rooms.

Configure these environment variables on the server (do not commit the access token):
- `WHATSAPP_OWNER` = owner WhatsApp number in international format, e.g. `9477xxxxxxx`
- `WHATSAPP_MANAGER` = manager WhatsApp number in international format
- `WHATSAPP_ACCESS_TOKEN` = Meta WhatsApp Cloud API access token
- `WHATSAPP_PHONE_NUMBER_ID` = WhatsApp Cloud API phone number ID
- `WHATSAPP_API_VERSION` = Graph API version, default `v23.0`

If the WhatsApp API variables are not configured, bookings still save normally and the server logs that notifications were not sent.
