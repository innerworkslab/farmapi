# Livestock ERP backend

## Farm management API

Import `../../docs/postman/farm/livestock-erp-farm-management-final.postman_collection.json`. Its collection name is **farm-management-final**. Run the folders in order; login, demo discovery, and workflow responses capture the IDs automatically. Default URL: `http://127.0.0.1:8000/api/v1`. The seeded `superadmin@example.com` account can run the workflows; the regular admin role only has farm read access.

Apply pending migrations with `php artisan migrate`. The farm migration creates new tables and preserves existing records. Add the isolated farm demo with `php artisan db:seed --class=Database\Seeders\Demo\DemoFarmsSeeder`; existing auth/setup seed data must already be present. The demo seeder is repeatable and does not reset the database.

Implemented backend workflows:

- Animal receiving supports batches and individual RFIDs, partial deliveries, paid/free quantities, bio data, destination validation and duplicate protection. Equipment receiving supports serial/asset units and explicitly selected quantity tracking. Confirming receipts posts stock once; rejected quantities require a reason and never enter stock.
- Farm operations (`POST farms/operations`) use `type=health|medication|mortality|weight`, a required idempotency key, explicit animal targets and a validated payload. Drafts can be edited with their current version, submitted, rejected or confirmed. Confirmed records are corrected through `/reverse`, preserving the original record and appending stock compensation where applicable.
- Health updates preserve active quantity. Mortality reduces live counts, retains dead-animal details and blocks feeding/treatment for dead animals. Medication consumes eligible medicine only on confirmation and records dosage totals, variance, cost, withdrawal periods and follow-up. Dosage uses the configured stock UOM.
- Feeding supports batch, individual and multiple individual targets in one farm. Confirmation records consumption/cost; `/reverse` restores stock with an original-ledger link. Planned feed is entered through `farms/{farm}/feeding-plans`; summaries compare plans with confirmed actual feed per animal and food/UOM. Group feeding is allocated equally across selected individuals.
- Farm settings control activity status, timezone and optional mortality/variance/wastage/sickness thresholds. Farm summaries, timelines, mortality rates, alerts, investigations and internal manager/veterinarian notifications use real records. Follow-ups can be completed through `operations/{operation}/complete-follow-up`. Corrected mortality alerts retain their history and resolve their active flag.

All routes require active authenticated users, branch access, and the relevant action permission (`farms.view` or `farms.manage`, or purchasing permissions for receipts). Histories retain batch/RFID and location snapshots. Whole-unit quantities, live counts, eligible lot status, expiry and cold-chain checks are revalidated at confirmation.

Farm API endpoints cover navigation/animal views (FARM-US-001–007), feeding (008–012), medication (013–018), farm-owned histories (019–023), health (024–026), mortality (027–031) and dashboard/alerts (032–035). Transfer, sale, reproduction/production history can display source ledger records when those separate modules supply them; their transaction workflows and the frontend UI are separate dependencies. Attachments are references, not a new upload service. Notifications are internal API records, not email/SMS delivery. Current headcounts are current-state metrics; the selected summary date filters daily activities. A zero starting count has an undefined daily mortality percentage (`null`). Compare consumption using item/UOM breakdowns when units differ.

Validation: the automated tests use SQLite in memory. The final collection was also executed against a separate SQLite API database, including its capture scripts and expected failure responses.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
