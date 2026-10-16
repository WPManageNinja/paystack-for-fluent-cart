<?php

// Runs after WordPress and plugins are loaded by WPLoader.
\FluentCart\Database\DBMigrator::migrate();

// Reuse the Pro suite's fixture factories (Tests\Support\Factory\*).
foreach (glob(WP_PLUGIN_DIR . '/fluent-cart-pro/dev/wp-browser/tests/Support/Factory/*.php') as $factoryFile) {
    require_once $factoryFile;
}
