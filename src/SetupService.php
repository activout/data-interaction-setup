<?php
declare(strict_types=1);

namespace App;


use Exception;
use Google\Client;
use Google_Client;
use Google_Service_SQLAdmin;
use Google_Service_SQLAdmin_SslCert;
use Google_Service_SQLAdmin_SslCertsInsertRequest;
use PDO;
use PDOStatement;
use SendGrid\Mail\Attachment;
use SendGrid\Mail\Mail;
use SendGrid\Mail\TypeException;

class SetupService
{
    private PDO $pdo;

    private string $googleProject = "data-interaction-300815";
    private string $googleInstance = "data-interaction";

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
        if (!SetupService::endsWith($email, "@activout.se") &&
            !SetupService::endsWith($email, "@hyperisland.com") &&
            !SetupService::endsWith($email, "@hyperisland.se")
        ) {
            throw new SetupException("Invalid e-mail address");
        }

        $user = $this->createOrUpdateUser($email);

        $this->verifyEmailAddress($user);
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
        } catch (Exception $e) {
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
    private function verifyEmailAddress(UserModel $user)
    {
        $encodedEmail = urlencode($user->email);
        $encodedSecret = urlencode($user->secret);

        $host = $_SERVER['HTTP_HOST'];     // HACK!

        if ($host == "localhost:8080") {
            $root = "http://localhost:8080";
        } else {
            $root = "https://data-interaction-setup.activout.se";
        }
        $html = "Use this link to continue: <a href=\"$root/setup.php?email=$encodedEmail&secret=$encodedSecret\">Create my databases and send me my password</a>";

        $this->sendEmail($user, "Verify e-mail", $html);
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

        $this->expireSecret($email);

        $prefix = preg_replace(["/@.*/", "/[^a-zA-Z0-9]*/"], "", $email);


        $client = new Google_Client();
        $client->setApplicationName("data-interaction-setup");
        $client->useApplicationDefaultCredentials();
        $client->addScope([\Google_Service_SQLAdmin::CLOUD_PLATFORM, \Google_Service_SQLAdmin::SQLSERVICE_ADMIN]);

        $service = new Google_Service_SQLAdmin($client);

        $sslCerts = $service->sslCerts->listSslCerts($this->googleProject, $this->googleInstance);
        /** @var Google_Service_SQLAdmin_SslCert $sslCert */
        foreach ($sslCerts as $sslCert) {
            if ($sslCert->commonName == $prefix) {
                $deleteResponse = $service->sslCerts->delete($this->googleProject, $this->googleInstance, $sslCert->sha1Fingerprint);
                $this->waitForOperation($service, $deleteResponse);
                break;
            }
        }

        $request = new Google_Service_SQLAdmin_SslCertsInsertRequest();
        $request->commonName = $prefix;
        $response = $service->sslCerts->insert($this->googleProject, $this->googleInstance, $request);

        $testName = $prefix . "_test";
        $prodName = $prefix . "_prod";

        $this->createDatabase($testName);
        $this->createDatabase($prodName);

        try {
            $testPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))), 0, 16);
            $prodPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))), 0, 16);
        } catch (Exception $e) {
            throw new SetupException("Failed to generate passwords", 0, $e);
        }

        $this->createUser($testName, $testPassword);
        $this->createUser($prodName, $prodPassword);

        $this->grantAccessToDatabase($testName, $testName);
        $this->grantAccessToDatabase($prodName, $prodName);

        $html = <<<EOF
<p>See attachments for MySQL SSL files.</p>
<h2>Test database</h2>
<h3>Database name</h3>
<p>$testName</p>
<h3>Username</h3>
<p>$testName</p>
<h3>Password</h3>
<p>$testPassword</p>
<h2>Production database</h2>
<h3>Database name</h3>
<p>$prodName</p>
<h3>Username</h3>
<p>$prodName</p>
<h3>Password</h3>
<p>$prodPassword</p>
EOF;

        try {
            $this->sendEmail($user, "Your database credentials", $html, [
                new Attachment(
                    base64_encode($response->getServerCaCert()->cert),
                    'application/x-pem-file',
                    "server-ca.pem"
                ),
                new Attachment(
                    base64_encode($response->getClientCert()->getCertInfo()->cert),
                    'application/x-pem-file',
                    "client-cert.pem"
                ),
                new Attachment(
                    base64_encode($response->getClientCert()->certPrivateKey),
                    'application/x-pem-file',
                    "client-key.pem"
                )
            ]);
        } catch (TypeException $e) {
            throw new SetupException("SendGrid error", 0, $e);
        }

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
        $query = "CREATE USER IF NOT EXISTS '$userName'@'%';";
        $statement = $this->prepare($query);
        $statement->execute();

        $this->setPassword($userName, $password);
    }

    private function grantAccessToDatabase(string $databaseName, string $userName)
    {
        // SQL injection danger zone!
        $query = "GRANT ALL PRIVILEGES ON `$databaseName`.* TO '$userName'@'%';";
        $statement = $this->prepare($query);
        $statement->execute();
    }

    /**
     * @param UserModel $user
     * @param string $title
     * @param string $html
     * @param $attachments
     * @throws SetupException
     */
    private function sendEmail(UserModel $user, string $title, string $html, $attachments = null): void
    {
        try {
            $email = new Mail();
            $email->setFrom("david+FED22STO@activout.se", "David Eriksson");
            $email->setSubject("[FED22STO Data Interaction] {$title}");
            $email->addTo($user->email);
            $email->addContent("text/plain", "See the HTML");
            $email->addContent(
                "text/html", $html
            );
            if (isset($attachments)) {
                $email->addAttachments($attachments);
            }

            $sendgrid = new \SendGrid(getenv('SENDGRID_API_KEY'));
            $response = $sendgrid->send($email);
            error_log("{$response->statusCode()}");
            error_log(print_r($response->headers(), true));
            error_log($response->body());
        } catch (Exception $e) {
            throw new SetupException("SendGrid error", 0, $e);
        }
    }

    /**
     * @param Google_Service_SQLAdmin $service
     * @param \Google_Service_SQLAdmin_Operation $operation
     */
    private function waitForOperation(Google_Service_SQLAdmin $service, \Google_Service_SQLAdmin_Operation $operation): void
    {
        $delay = 1;
        while ($operation->status != "DONE") {
            $delay *= 2;
            sleep($delay);
            $operation = $service->operations->get($this->googleProject, $operation->name);
        }
    }

    private function setPassword(string $userName, string $password)
    {
        // SQL injection danger zone!
        $query = "ALTER USER '$userName'@'%' IDENTIFIED BY '$password';";
        $statement = $this->prepare($query);
        $statement->execute();
    }

    private function expireSecret(string $email)
    {
        $query = "update users set secret=null where email=:email;";
        $statement = $this->prepare($query);
        $statement->execute(compact('email'));
    }
}

