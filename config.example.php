<?php
// Copy OUTSIDE public_html as shizufi-config.php. Never commit the populated copy.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'YOUR_DATABASE_NAME',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => 'REPLACE_ON_SERVER',
    // Generate a random installation key (at least 32 characters). Used once.
    'setup_token' => 'REPLACE_WITH_RANDOM_INSTALLATION_KEY',
    'secure_cookies' => true,
];
