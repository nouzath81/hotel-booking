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
