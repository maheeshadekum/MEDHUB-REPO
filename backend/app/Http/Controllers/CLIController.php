<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use UAParser\Parser;

class CLIController extends Controller
{
    /**
     * RUN: Migrations
     *
     * Run the migration to create the database tables.
     *
     * @return JsonResponse
     */
    public function migrateDatabase(): JsonResponse
    {
        try {
            Artisan::call('migrate', [
                '--force' => true,
            ]);

            // Get IP address
            $ipAddress = request()->ip();

            // Get User-Agent (device info)
            $userAgent = request()->header('User-Agent');

            $parser = Parser::create();
            $result = $parser->parse($userAgent);

            return response()->json([
                'message' => 'Database migration completed successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to run database migration',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * RUN: Seed Database
     *
     * Run the db:seed command to seed the database with initial data.
     *
     * @return JsonResponse
     */
    public function seedDatabase(): JsonResponse
    {
        try {
            Artisan::call('db:seed', [
                '--force' => true,
            ]);

            // Get IP address
            $ipAddress = request()->ip();

            // Get User-Agent (device info)
            $userAgent = request()->header('User-Agent');

            $parser = Parser::create();
            $result = $parser->parse($userAgent);

            return response()->json([
                'message' => 'Database seeding completed successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to run database seeding',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * RUN: Rollback Migrations
     *
     * Run the rollback command to revert the last database migration.
     *
     * @return JsonResponse
     */
    public function rollbackDatabase(): JsonResponse
    {
        try {
            Artisan::call('migrate:rollback', [
                '--force' => true,
            ]);

            // Get IP address
            $ipAddress = request()->ip();

            // Get User-Agent (device info)
            $userAgent = request()->header('User-Agent');

            $parser = Parser::create();
            $result = $parser->parse($userAgent);

            return response()->json([
                'message' => 'Database rollback completed successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to run database rollback',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * RUN: storage:link
     *
     * Run the storage:link command to create a symbolic link from public/storage to storage/app/public.
     *
     * @return JsonResponse
     */
    public function createStorageLink(): JsonResponse
    {
        try {
            Artisan::call('storage:link');

            // Get IP address
            $ipAddress = request()->ip();

            // Get User-Agent (device info)
            $userAgent = request()->header('User-Agent');

            $parser = Parser::create();
            $result = $parser->parse($userAgent);

            return response()->json([
                'message' => 'Storage link created successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to create storage link',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * RUN: cache:clear
     *
     * Run the cache:clear command to clear the application cache.
     *
     * @return JsonResponse
     */

    public function clearCache(): JsonResponse
    {
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');

            // Get IP address
            $ipAddress = request()->ip();

            // Get User-Agent (device info)
            $userAgent = request()->header('User-Agent');

            $parser = Parser::create();
            $result = $parser->parse($userAgent);

            return response()->json([
                'message' => 'Application cache cleared successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to clear application cache',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
