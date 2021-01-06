<?php
declare(strict_types=1);

namespace App;


use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

class SetupApi
{
    /**
     * @var SetupService
     */
    private SetupService $setupService;


    /**
     * CounterApi constructor.
     * @param SetupService $setupService
     */
    public function __construct(SetupService $setupService)
    {
        $this->setupService = $setupService;
    }

    public function init(Group $group)
    {
        $group->post('', function (Request $request, Response $response, $args) {
            $input = json_decode(file_get_contents('php://input'));
            $email =  $input->email;

            $email = trim($email);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $response->withStatus(400);
            }

            $this->setupService->beginSetup($email);
            return $response;
        });
        $group->get('/{id}', function (Request $request, Response $response, $args) {
            $response->getBody()->write(json_encode($this->setupService->getUserByEmail((int)$args['id'])));
            return $response->withHeader('Content-Type', 'application/json');
        });
        $group->post('/{id}', function (Request $request, Response $response, $args) {
            $response->getBody()->write(json_encode($this->setupService->increaseCounter((int)$args['id'])));
            return $response->withHeader('Content-Type', 'application/json');
        });
    }
}
