<h1 align="center">
    <img src="./assets/images/logo.svg" width="350" alt="Omnia Dev Logo"/><br>
    Omnia Developer Utilities
</h1>

Automated developer tooling for Omnia Drupal modules to instantly scaffold and manage local DDEV test environments
powered by **ESN Satellite 5**.

---

## Features

- **Automated Satellite 5 Scaffolding**: Runs `composer create-project esn/satellite-project:^5.0` targeting the test
  environment directory.
- **Dependency Detection**: Automatically discovers your module's requirements from `.info.yml` and `composer.json` and
  installs them into the test environment.
- **Loop-Safe Relative Symlinks**: Symlinks `src/`, `assets/`, `config/`, `templates/`, and all module root files into
  `web/modules/custom/<module>` using relative paths that resolve seamlessly on macOS, Linux, and inside Docker
  containers while preventing infinite recursive scanning loops.
- **Automated DDEV Configuration**:
    - Configures DDEV with `docroot: web` and PHP 8.3+.
    - Creates `.ddev/docker-compose.module.yaml` to mount your working module repository into the container.
    - Automatically registers `post-start` hooks to ensure container symmetry.
- **Automated Drupal & Satellite Installation**: Runs `drush site:install satellite`, enables your custom module, and
  prints a one-time admin login link.

---

## Installation

Add `omnia-dev` to your Drupal module's `composer.json` under `require-dev`:

```bash
composer require --dev esn-cy/omnia-dev
```

*(Or point to your local path repository during development).*

---

## Usage

### 1. Command Line Interface (CLI)

Run the CLI binary from your module repository root:

```bash
# Full end-to-end setup (Satellite project, DDEV, symlinks, site-install, enable module)
./vendor/bin/omnia-dev init

# Options:
./vendor/bin/omnia-dev init --dir=test-env --php=8.3 --account-name=admin --account-pass=admin --force

# Update or refresh module symlinks if you added new directories/files
./vendor/bin/omnia-dev link

# Generate a one-time admin login link for the test environment
./vendor/bin/omnia-dev uli
```

### 2. Composer Scripts Integration

Add the script handlers to your module's `composer.json`:

```json
{
  "scripts": {
    "dev:setup": "Drupal\\omnia_dev\\Composer\\ScriptHandler::setup",
    "dev:link": "Drupal\\omnia_dev\\Composer\\ScriptHandler::link",
    "dev:uli": "Drupal\\omnia_dev\\Composer\\ScriptHandler::uli"
  }
}
```

Now any developer checking out your repository can run:

```bash
# Sets up DDEV, installs Satellite, and links your module
composer dev:setup

# Get an instant login URL
composer dev:uli

# Refresh symlinks
composer dev:link
```

---

## Licence

This project is under the [Apache 2.0](./LICENSE) Licence.
