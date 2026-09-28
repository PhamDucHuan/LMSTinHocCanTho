<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    // The registration form and endpoint accept up to 191 characters. Keep
    // upgraded databases aligned with fresh installations and that contract.
    $pdo->exec(
        'ALTER TABLE users
         MODIFY COLUMN name VARCHAR(191) NOT NULL,
         MODIFY COLUMN email VARCHAR(191) NOT NULL'
    );
};
