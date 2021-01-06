<?php
declare(strict_types=1);

namespace App;


use Exception;
use PDO;
use PDOStatement;
use SendGrid\Mail\Mail;

class SetupService
{
    private PDO $pdo;

    /**
     * Constructor.
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $query
     * @return bool|PDOStatement
     */
    private function prepare(string $query)
    {
        return $this->pdo->prepare($query);
    }

    public function getUserByEmail(string $email): ?UserModel
    {
        $query = "select * from users where email=:email";
        $statement = $this->prepare($query);
        $statement->execute(compact('email'));
        return $statement->fetchObject(UserModel::class) ?: null;
    }

    public function getUserByEmailAndSecret(string $email, string $secret): ?UserModel
    {
        $query = "select * from users where email=:email and secret=:secret";
        $statement = $this->prepare($query);
        $statement->execute(compact('email', 'secret'));
        return $statement->fetchObject(UserModel::class) ?: null;
    }

    /**
     * @param $email
     * @throws SetupException
     */
    public function beginSetup($email)
    {
        if (!SetupService::endsWith($email, "@hyperisland.se") &&
            !SetupService::endsWith($email, "@activout.se")
        ) {
            throw new SetupException("Invalid e-mail address");
        }

        $user = $this->createOrUpdateUser($email);

        $this->sendEmail($user);
    }

    private static function endsWith($haystack, $needle): bool
    {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }

    /**
     * @param $email
     * @return UserModel|null
     * @throws SetupException
     */
    private function createOrUpdateUser($email)
    {
        try {
            $secret = base64_encode(random_bytes(32));
        } catch (\Exception $e) {
            throw new SetupException("Failed to generate secret", 0, $e);
        }

        $query = "insert into users (email,secret) values (:email,:secret) ON DUPLICATE KEY UPDATE secret=:secret;";
        $statement = $this->prepare($query);
        $statement->execute(compact('email', 'secret'));

        return $this->getUserByEmail($email);
    }

    /**
     * @param UserModel $user
     * @throws SetupException
     */
    private function sendEmail(UserModel $user)
    {
        $encodedEmail = urlencode($user->email);
        $encodedSecret = urlencode($user->secret);

        try {
            $email = new Mail();
            $email->setFrom("david@activout.se", "David Eriksson");
            $email->setSubject("Database setup for FED22STO Data Interaction");
            $email->addTo($user->email);
            $email->addContent("text/plain", "See the HTML");
            $email->addContent( // https://FED22STO.activout.se
                "text/html", "Use this link to continue: <a href=\"http://localhost:8080/setup.php?email=$encodedEmail&secret=$encodedSecret&XDEBUG_SESSION=asd\">Create my databases and send me my password</a>"
            );
            $sendgrid = new \SendGrid(getenv('SENDGRID_API_KEY'));
            $response = $sendgrid->send($email);
            error_log("{$response->statusCode()}");
            error_log(print_r($response->headers(), true));
            error_log($response->body());
        } catch (\Exception $e) {
            throw new SetupException("SendGrid error", 0, $e);
        }
    }

    /**
     * @param string $email
     * @param string $secret
     * @throws SetupException
     */
    public function setupStep2(string $email, string $secret)
    {
        $user = $this->getUserByEmailAndSecret($email, $secret);
        if (!$user) {
            throw new SetupException("User not found or secret has expired");
        }

        $prefix = preg_replace(["/@.*/", "/[^a-zA-Z0-9]*/"], "", $email);
        $testName = $prefix . "_test";
        $prodName = $prefix . "_prod";

        $this->createDatabase($testName);
        $this->createDatabase($prodName);

        try {
            $testPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))), 0, 16);
            $prodPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))), 0, 16);
        } catch (\Exception $e) {
            throw new SetupException("Failed to generate passwords", 0, $e);
        }

        $this->createUser($testName, $testPassword);
        $this->createUser($prodName, $prodPassword);

        $this->grantAccessToDatabase($testName, $testName);
        $this->grantAccessToDatabase($prodName, $prodName);
    }

    private function createDatabase(string $databaseName)
    {
        // SQL injection danger zone!
        $query = "CREATE DATABASE IF NOT EXISTS `$databaseName`;";
        $statement = $this->prepare($query);
        $statement->execute();
    }

    private function createUser(string $userName, string $password)
    {
        // SQL injection danger zone!
        $query = "CREATE USER IF NOT EXISTS '$userName'@'%' IDENTIFIED BY '$password';";
        $statement = $this->prepare($query);
        $statement->execute();
    }

    private function grantAccessToDatabase(string $databaseName, string $userName)
    {
        // SQL injection danger zone!
        $query = "GRANT ALL PRIVILEGES ON `$databaseName`.* TO '$userName'@'%';";
        $statement = $this->prepare($query);
        $statement->execute();
    }
}

