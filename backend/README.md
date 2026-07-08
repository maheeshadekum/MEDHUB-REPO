# SimpLinkX API

SimpLinkX – Hospital Management System is a digital solution designed to
address hospital management challenges, based on requirements gathered from
Kandegedara Divisional Hospital in Badulla District, Sri Lanka, with input
from both staff and patients. It will create NIC-based patient profiles containing
medical history, prescriptions, and health indicators such as blood pressure and
sugar levels. The system will interlink nearby government hospitals to help locate
required medications, and while it won’t show exact availability, it will display
nearby Rajya Osusala Outlets. A digital inventory management module will
monitor stock levels, alert staff of upcoming expiries, and notify when restocking
is needed. Patients will be able to pre-book appointments online to receive time-
based tokens, and their data will be accessible from any government hospital, with
all records synchronized across the network.

## Project Structure

The project is organized into several key directories:

-   **app/Http/Controllers**: Contains controllers for handling authentication and role-specific actions.
-   **app/Models**: Defines the models for users, roles, permissions, and patients.
-   **app/Policies**: Contains authorization logic for permissions.
-   **database/factories**: Includes factories for generating fake data for testing and seeding.
-   **database/migrations**: Contains migration files for creating necessary database tables.
-   **database/seeders**: Includes seeders for populating the database with initial data.
-   **routes**: Defines the API routes for the application.

## Installation

1. Clone the repository:

    ```
    git clone <repository-url>
    ```

2. Navigate to the project directory:

    ```
    cd SimpLinkX-API
    ```

3. Install dependencies:

    ```
    composer install
    ```

4. Set up your `.env` file:

    ```
    cp .env.example .env
    ```

5. Generate the application key:

    ```
    php artisan key:generate
    ```

6. Run migrations to set up the database:

    ```
    php artisan migrate
    ```

7. Seed the database with initial data:
    ```
    php artisan db:seed
    ```

## Usage

-   Access the API endpoints defined in `routes/api.php` to interact with the application.
-   Use the controllers to manage user authentication and role-specific functionalities.
