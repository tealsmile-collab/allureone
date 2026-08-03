# Allure Thai Spa Deals

Production-ready PHP SPA for Allure Thai Spa deal shopping — Amazon-inspired UX with luxury spa branding.

## Stack

- PHP 8.3+, MySQL, PDO, MVC-style modules
- Bootstrap 5, jQuery, Swiper, SweetAlert2, Toastr, Font Awesome
- Razorpay Standard Checkout (INR / India)
- Gallabox WhatsApp order notifications

## Deploy on Hostinger

1. Upload all files to your domain document root (e.g. `public_html/deals`).
2. Update `config.php`:
   - `site_url`
   - Razorpay `key_id` / `key_secret`
   - Gallabox `api_key` / `api_secret`
3. Import schema:
   - phpMyAdmin → import `database/schema.sql`
   - **or** visit `https://your-domain/install.php?key=allure-install-once` once, then **delete** `install.php`
4. Ensure `uploads/` is writable (`755` or `775`).
5. Login at `/admin/login.php` using an `allureone_users` account with role **admin** or **superadmin**.

## Demo coupons (seeded)

- `ALLURE10` — 10% off
- `SPA500` — ₹500 off (min ₹2499)

## Key folders

| Path | Purpose |
|------|---------|
| `ajax/` | Public SPA JSON APIs |
| `admin/` | Admin panel |
| `database/schema.sql` | Full schema + seed data |
| `includes/` | Models, services, helpers |
| `uploads/` | Product/slider/invoice files |

## Phases covered

1. Database + config + MVC core  
2. Homepage / hero / products / filters  
3. Admin modules  
4. Cart / checkout / coupons  
5. Razorpay / invoice / email / WhatsApp  
6. Security / SEO / performance basics  

## Notes

- While Razorpay keys are placeholders (`XXXX`), checkout runs in **demo payment mode**.
- WhatsApp sends are skipped until Gallabox credentials are set.
- GST default is **18%** (configurable in `config.php`).
