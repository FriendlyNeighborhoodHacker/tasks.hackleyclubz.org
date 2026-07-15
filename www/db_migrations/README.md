# Database Migrations

This directory contains database migration files for the RAG application.

## Migration File Naming Convention

Migration files should be named using the format: `YYYY-MM-DD_description.sql`

Example: `2025-01-15_add_documents_table.sql`

## Running Migrations

Migrations should be run in chronological order. Each migration file should contain SQL statements that can be executed safely multiple times (idempotent).

## Important Notes

- Always update `schema.sql` in the root directory to reflect the current state after creating migrations
- The `schema.sql` file should represent the complete database structure without requiring any migrations to be run
- Test migrations on a copy of production data before applying to production
- Include both UP and DOWN migration paths when possible

## Current Schema Version

The current schema is maintained in `/schema.sql` and includes:
- Users table with authentication fields
- Settings table for application configuration
- Default settings for site title, announcement, and timezone
