# Environment Configuration

Environment files follow a **template and instance pattern**.

**Related guides:**
- [bootstrap-booters.md](bootstrap-booters.md) - Application configuration

## File Structure

```
.env                # Local environment (git-ignored)
.env.testing        # Testing environment (git-ignored)
.env-local          # Template for local development (committed)
.env-testing-local  # Template for testing (committed)
.env.production     # Production config (optional - encrypted with git-crypt)
.env.staging        # Staging config (optional - encrypted with git-crypt)
```

## Template Pattern

### Template Files (Committed)

`.env-local` - Template for local development:

```env
APP_NAME="Laravel App"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=

MAIL_MAILER=log
QUEUE_CONNECTION=sync
```

**Committed to git** for other developers to copy.

### Instance Files (Ignored)

`.env` - Actual local environment:

```env
APP_NAME="My Laravel App"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://my-app.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_app
DB_USERNAME=root
DB_PASSWORD=secret

STRIPE_API_KEY=sk_test_...
MAIL_MAILER=smtp
QUEUE_CONNECTION=redis
```

**Never committed to git** - contains secrets and local values.

## Setup for New Developers

```bash
# Copy template files to create local environment
cp .env-local .env
cp .env-testing-local .env.testing

# Generate application key
php artisan key:generate

# Update .env with your local values
# - Database password
# - API keys
# - Local URLs
# - etc.
```

## .gitignore Configuration

```
.env
.env.testing
.env.backup
.env.production
.env.staging
```

**Do not ignore:**
- `.env-local`
- `.env-testing-local`

## Optional: Git-Crypt for Production

Some projects use **git-crypt** to encrypt production/staging configs in git.

### Setup

```bash
# Install
brew install git-crypt

# Initialize in repository
git-crypt init

# Configure .gitattributes
echo ".env.production filter=git-crypt diff=git-crypt" >> .gitattributes
echo ".env.staging filter=git-crypt diff=git-crypt" >> .gitattributes

# Commit
git add .gitattributes
git commit -m "Add git-crypt for production secrets"
```

### Grant Access

```bash
# Export key for team members
git-crypt export-key /path/to/key-file

# Team members unlock with key
git-crypt unlock /path/to/key-file
```

## Key Principles

1. **Templates committed** - `.env-local` provides starting point
2. **Instances ignored** - `.env` never committed
3. **Secrets stay local** - API keys, passwords never in git
4. **Optional encryption** - Git-crypt for production when needed
5. **Easy setup** - New developers just copy templates

## Summary

**Template pattern ensures:**
- New developers can start quickly
- No secrets in git (unless encrypted)
- Consistent structure across team
- Clear separation of config and secrets
