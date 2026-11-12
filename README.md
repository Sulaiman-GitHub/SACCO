# SACCO Management System

A multi-tenant web application for Ugandan SACCOs (savings and credit cooperatives). Each SACCO gets its own isolated workspace for managing members, savings, loans and repayments, with reports, an audit trail, a read-only member portal, and loan default risk scoring from a machine-learning model.

- **Web app:** Laravel (PHP 8.3), Blade, Bootstrap 5
- **Risk scoring:** a separate Python (FastAPI + scikit-learn) service in `ml_service/`. If it is offline the app falls back to a simple built-in estimate, so loan operations keep working.
- **Design and decisions:** see `SYSTEM_ARCHITECTURE.md`

## Setup on a new computer

### 1. Requirements

- PHP 8.3 with the `sqlite3`, `mbstring`, `openssl`, `pdo_sqlite` and `curl` extensions
- [Composer](https://getcomposer.org)
- Node.js and npm
- Python 3.10+ (for the ML service)

### 2. Web application

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

Local development uses SQLite by default (`database/database.sqlite`; create the empty file if it is missing). `php artisan migrate --seed` creates the tables and demo SACCOs, staff and members. Demo logins are defined in `database/seeders/DatabaseSeeder.php`. Change them before any real deployment.

`.env.example` ships with `APP_DEBUG=false`. **For local development set `APP_DEBUG=true` in your `.env`** to see detailed error pages. Keep it `false` on any shared or production server.

Start the app:

```sh
composer run dev
```

or just `php artisan serve` (open http://127.0.0.1:8000).

To flag overdue and defaulted loans automatically, run the scheduler (`php artisan schedule:work` locally, or a cron entry calling `schedule:run` every minute on a server). The scan can also be run by hand with `php artisan loans:check-overdue`.

### 3. ML scoring service

The virtual environment lives inside `ml_service/venv`. Run these from the project root:

```sh
python -m venv ml_service/venv
ml_service\venv\Scripts\activate        # Windows
# source ml_service/venv/bin/activate   # macOS / Linux
pip install -r ml_service/requirements.txt
python ml_service/run.py
```

The service listens on `http://127.0.0.1:8001` (`GET /health`, `GET /model-info`, `POST /score`). The web app finds it through `ML_SCORING_URL` and `ML_SCORING_TIMEOUT` in `.env`. A trained model is included; to retrain it, run `python -m ml_service.train`.

Tip: keep the project in a short path, since deep folder paths can exceed Windows path limits when installing Python packages.

### Staff email invitations and password reset

There is no self-registration. An administrator adds a staff member and, if the password fields are left blank, the person is emailed a one-time link (valid 48 hours) to choose their own password at `/staff/invite/{token}`. "Forgot your password?" on the staff sign-in page emails a reset link (valid 60 minutes) via `/forgot-password` and `/reset-password/{token}`; it works only for existing active staff of an active SACCO. Run `php artisan migrate` (new `staff_invitations` table) and set the `MAIL_*` values in `.env` (default `MAIL_MAILER=log` only writes emails to the log). See [docs/EMAIL_SETUP.md](docs/EMAIL_SETUP.md). If email is unavailable, an administrator can set a temporary password from the Staff screen, and sign-in never depends on mail.

## Running the tests

Web application (uses an in-memory SQLite database):

```sh
php artisan test
```

ML service (from the project root, with the virtual environment active):

```sh
python -m pytest ml_service/tests
```

## Useful commands

```sh
php artisan audit:verify              # check the audit trail and ledgers for tampering
php artisan credit:score-member {id}  # score one member from the command line
```

## License

Released under the [MIT license](https://opensource.org/licenses/MIT).
