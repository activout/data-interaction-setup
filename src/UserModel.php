<?php
declare(strict_types=1);

namespace App;


class UserModel
{
    public int $id;
    public string $email;
    public ?string $secret;
}
