Easy billing through Google Calendar
===

This script could help you calculate the time spent doing some activities and the wages for those.
Works best if the calendar entries are in the format «[Client name] [Optional comments]»

Installation
---
1. Prerequisites: PHP 5.4+, Composer.
2. Clone this repository.
3. Run `composer install` inside the project directory.
4. Register an app with Calendar API in the [Google Developers Console](https://console.developers.google.com)
5. Create a `config.php` file with the following contents:

        <?php
        
        $cfg = array_merge($cfg, [
            'appId' => 'your client ID',
            'appSecret' => 'your client secret',
        ]);
    
6. Open the web address.