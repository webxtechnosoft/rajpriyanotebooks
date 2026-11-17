RAJPRIYA NOTEBOOKS - CLEANED PROJECT PACKAGE

What was done:
- Kept the application PHP, assets, uploads needed by the site, Composer dependencies, and product-code workbook.
- Replaced the multiple migration SQL files with one consolidated database.sql schema.
- Removed scratch/debug/test PHP scripts, the nested old ZIP, cookie.txt, generated invoice PDF exports, and separate migration SQL files.
- Removed demo customer/product/order/review seed records from database.sql; category and basic site settings seeds remain.

Database setup:
1. Back up your current database before importing anything.
2. For a fresh database only, import database.sql in phpMyAdmin.
3. Update config/database.php for your hosting database credentials.
4. Initial admin credentials in the current application are username: admin, password: admin@123. Change them immediately after login.

Important limitations:
- This SQL is for a fresh install. It is NOT a data-preserving upgrade script and must not be imported over a live database without a backup.
- Product/customer/order data was not available as a database dump in the uploaded ZIP, so this package cannot preserve live database records.
- Static review cannot guarantee production behavior; test login, product varieties, cart, checkout, stock, invoice, and parcel flows on a staging copy before deployment.
