# Allure Thai Spa Deals Web Application - Product Requirements Document (PRD)

## Role

You are a senior PHP Solution Architect, UX Designer, Database
Architect, Security Expert, and Full Stack Developer.

Design and develop a **production-ready**, **high-performance**,
**responsive**, **Single Page Application (SPA)** for **Allure Thai Spa
Deals**.

The application should feel as polished, conversion-focused, and
user-friendly as Amazon India while maintaining a premium luxury spa
aesthetic.

## Objectives

-   Maximize Conversion Rate
-   Increase Average Order Value
-   Premium User Experience
-   Mobile First
-   High Performance
-   AJAX Driven SPA

------------------------------------------------------------------------

# Technology Stack

## Backend

-   PHP 8.3+
-   MySQL (Hostinger)
-   PDO
-   MVC Architecture
-   REST-style AJAX APIs

## Frontend

-   HTML5
-   CSS3
-   Bootstrap 5
-   jQuery
-   Vanilla JavaScript
-   AJAX
-   SweetAlert2
-   Toastr
-   SwiperJS
-   Font Awesome

## Payment

-   Razorpay Standard Checkout

## Hosting

-   Hostinger Shared Hosting

## Database

Use existing database:

`u716393246_AllurePro`

------------------------------------------------------------------------

# Authentication

Use existing table:

`allureone_users`

Only users having role:

-   admin
-   superadmin

can access admin panel.

------------------------------------------------------------------------

# Configuration

Create `config.php` containing:

-   Database Connection
-   Site URL
-   Company Name
-   Logo
-   Razorpay Key ID
-   Razorpay Secret
-   GST
-   Currency
-   Support Email
-   Support Phone
-   Offer Settings
-   Global Application Settings

------------------------------------------------------------------------

# UX Requirements

-   Premium Luxury Design
-   Amazon-inspired shopping experience
-   No page reloads
-   AJAX everywhere
-   Skeleton loaders
-   Lazy loading
-   Sticky header
-   Sticky mobile cart
-   Responsive
-   Fast loading

------------------------------------------------------------------------

# Homepage

## Navigation

-   Logo
-   Search
-   Categories
-   Today's Deals
-   Offers
-   Cart
-   Admin Login

## Hero Carousel

Admin configurable.

Each slide contains: - Desktop Image - Mobile Image - Heading - Sub
Heading - CTA Button - Navigation Link - Auto Rotate

------------------------------------------------------------------------

# Today's Deals

Amazon style cards displaying:

-   Limited Time Deal badge
-   Countdown timer
-   Product Image
-   Service Name
-   Description
-   Original Price (Strike)
-   Offer Price
-   Discount %
-   Save Amount
-   Rating
-   Add To Cart
-   Quick View

------------------------------------------------------------------------

# Filters

-   Price
-   Category
-   Discount
-   Branch
-   City
-   Duration
-   Sort

Live AJAX filtering.

------------------------------------------------------------------------

# Product Popup

AJAX popup containing:

-   Gallery
-   Description
-   Benefits
-   Duration
-   Price
-   Discount
-   Branch Selection
-   City Selection
-   Quantity
-   Coupon
-   Add to Cart
-   Buy Now
-   Related Services

------------------------------------------------------------------------

# Shopping Cart

AJAX Cart

Features: - Increase Quantity - Decrease Quantity - Remove Item -
Coupon - GST - Price Breakdown - Checkout

------------------------------------------------------------------------

# Checkout

Collect:

-   Name
-   Mobile
-   Email
-   Gender
-   Notes
-   City
-   Branch (Mandatory)

Integrate Razorpay for doing payment.Note that this web application will be used only in India so accordingly it should be done.

Store: - Payment ID - Razorpay Order ID - Signature - Payment Status

Generate: - Invoice - Order - Email Confirmation

------------------------------------------------------------------------

# Coupon Engine

## Marketing Coupons

-   Percentage Discount
-   Validity
-   Usage Limit
-   Categories
-   Cities
-   Branches
-   Enable / Disable

## One Time Coupons

-   Single Use
-   Auto Expire after successful payment

------------------------------------------------------------------------

# Admin Panel

Modules:

-   Dashboard
-   Products
-   Categories
-   Today's Deals
-   Hero Slider
-   Coupons
-   Orders
-   Customers
-   Reports
-   Settings
-   Policies
-   Logout

Dashboard Widgets: - Revenue - Orders - Coupon Usage - Top Products -
Sales Graph - Cities - Branches

------------------------------------------------------------------------

# Product Management

Fields:

-   Name
-   SEO URL
-   Category
-   Short Description
-   Long Description
-   Duration
-   Original Price
-   Offer Price
-   Discount %
-   Auto Strike Price
-   Image
-   Gallery
-   Today's Deal
-   Featured
-   Bestseller
-   Active
-   Display Order
-   SEO Title
-   SEO Description
-   SEO Keywords

------------------------------------------------------------------------

# Hero Slider

Configurable:

-   Desktop Image
-   Mobile Image
-   Heading
-   Sub Heading
-   CTA
-   Link
-   Priority
-   Schedule
-   Enable

------------------------------------------------------------------------

# Today's Deals Admin

Manage:

-   Offer Dates
-   Countdown
-   Discount
-   Products
-   Badge
-   Auto Expiry

------------------------------------------------------------------------

# Orders

Search and filter by:

-   Customer
-   Branch
-   City
-   Payment
-   Coupon
-   Status

Invoice generation included.

------------------------------------------------------------------------

# Reports

-   Revenue
-   Products
-   Coupons
-   Cities
-   Branches
-   GST
-   CSV Export

------------------------------------------------------------------------

# Policies

Create pages:

-   Privacy Policy
-   Terms & Conditions
-   Payment Policy
-   Cancellation Policy
-   Refund Policy
-   No Refund Policy
-   Gift Voucher Policy
-   Digital Product Policy

------------------------------------------------------------------------

# Categories

Examples:

-   Thai Massage
-   Swedish Massage
-   Deep Tissue
-   Hot Stone
-   Balinese
-   Potli Massage
-   Foot Reflexology
-   Facial
-   Body Scrub
-   Membership
-   Gift Voucher

------------------------------------------------------------------------

# SEO

-   SEO Friendly URLs
-   Meta Tags
-   OpenGraph
-   Twitter Cards
-   JSON-LD
-   Product Schema
-   Breadcrumbs
-   Canonical
-   XML Sitemap
-   Robots.txt

------------------------------------------------------------------------

# Performance

-   Lazy Loading
-   Image Compression
-   AJAX Pagination
-   Prepared Statements
-   XSS Protection
-   SQL Injection Prevention
-   CSRF Protection
-   Session Security

------------------------------------------------------------------------

# Database Tables

All tables use prefix `alluredeal_`

-   alluredeal_product
-   alluredeal_product_images
-   alluredeal_category
-   alluredeal_cart
-   alluredeal_cart_items
-   alluredeal_orders
-   alluredeal_order_items
-   alluredeal_coupon
-   alluredeal_coupon_usage
-   alluredeal_onetime_coupon
-   alluredeal_todaydeal
-   alluredeal_slider
-   alluredeal_customer
-   alluredeal_branch
-   alluredeal_city
-   alluredeal_settings
-   alluredeal_policy
-   alluredeal_order_status
-   alluredeal_payment_logs
-   alluredeal_activity_logs
-   alluredeal_wishlist

Include:

-   Primary Keys
-   Foreign Keys
-   Indexes
-   created_at
-   updated_at
-   created_by
-   updated_by
-   is_active
-   is_deleted

------------------------------------------------------------------------

# Folder Structure

``` text
/
admin/
ajax/
api/
assets/
config/
database/
includes/
uploads/
product/
cart/
checkout/
policy/
vendor/
index.php
config.php
.htaccess
```

------------------------------------------------------------------------

# Coding Standards

-   MVC
-   PSR Standards
-   Reusable Components
-   Modular Architecture
-   PDO Prepared Statements
-   AJAX APIs
-   JSON Responses
-   Fully Commented Code

------------------------------------------------------------------------

# Development Phases

## Phase 1

-   Database Design
-   SQL
-   MVC
-   Configuration

## Phase 2

-   Homepage
-   Hero
-   Products
-   Filters

## Phase 3

-   Admin Panel
-   Products
-   Categories
-   Coupons
-   Slider

## Phase 4

-   Cart
-   Checkout
-   Coupon Engine

## Phase 5

-   Razorpay
-   Orders
-   Invoice
-   Email

## Phase 6

-   Security
-   SEO
-   Optimization
-   Performance

Each phase must include: - Architecture explanation - Complete source
code - SQL scripts - Deployment-ready files for Hostinger.

database connection:
'db' => [
        'host' => '82.25.121.179',
        'user' => 'u716393246_allureproadmin',
        'password' => 'allure@Dmin123',
        'database' => 'u716393246_AllurePro',
        'charset' => 'utf8mb4',
    ]

tables:
-- Roles
SELECT id, RoleName, isActive
FROM allureone_roles
ORDER BY id;
-- Users
SELECT id, loginname, FullName, MobileNo, EmailId, BranchId, RoleId, isactive, RecordSale
FROM allureone_users
ORDER BY id;
-- Users with role name
SELECT
  u.id,
  u.loginname,
  u.FullName,
  u.MobileNo,
  u.EmailId,
  u.BranchId,
  u.RoleId,
  r.RoleName,
  u.isactive,
  u.RecordSale
FROM allureone_users u
LEFT JOIN allureone_roles r ON r.id = u.RoleId
ORDER BY u.id;

On placing order user will get whatsapp message with invoice details. 
To send whatsapp message
URL
https://server.gallabox.com/devapi/messages/whatsapp
Method
POST
Content-Type

in config file have
apiKey: <gallabox_api_key>
apiSecret: <gallabox_api_secret>
Content-Type: application/json

Sample payload
{
  "channelId": "68ad971bb42a9aef088df331",
  "channelType": "whatsapp",
  "recipient": {
    "name": "Thane",
    "phone": "919987799720"
  },
  "whatsapp": {
    "type": "template",
    "template": {
      "templateName": "meta_lead",
      "bodyValues": {
        "sourceName": "Order details",
        "customerNumber": "9198XXXXXXXX",
        "customerName": "Rahul Sharma",
        "details": "Preferred Location - Thane"
      }
    }
  }
}

channelId
Fixed Gallabox WhatsApp channel
recipient.name
Branch name (spa) or "Shailesh" (franchise default)
recipient.phone
Branch WhatsApp number from $branchPhones, or default 918369676845 for franchise
templateName
meta_lead
sourceName
"Meta Insta-Fb Lead" or "Meta Lead - Franchise"
customerNumber
Lead phone (digits only; 91 prefixed if 10 digits)
customerName
Lead full name
details
Location line, or franchise Q&A lines

Response JSON is treated as success when:

{ "status": "success" }