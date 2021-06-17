<?php

namespace App\Command;

use Discord\Slash\RegisterClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'bot:register',
    description: 'Add a short description for your command',
)]
class BotRegisterCommand extends Command
{
    private string $botPublicKey;
    private string $botToken;
    private HttpClientInterface $httpClient;

    public function __construct(string $botPublicKey, string $botToken, HttpClientInterface $httpClient)
    {
        parent::__construct('bot:register');
        $this->botPublicKey = $botPublicKey;
        $this->botToken = $botToken;
        $this->httpClient = $httpClient;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slashClient = new RegisterClient($this->botToken);
        $slashClient->createGlobalCommand('top', 'Envoie le top des étudiants (trié par la moyenne des domaines)',[
            ['name'=>'page','required'=>false,'type'=>4,'description'=>'Numéro de la page'],
            ['name'=>'per_page','required'=>false,'type'=>4,'description'=>'Nombre d\'éléments par page']
        ]);
        $slashClient->createGlobalCommand('notes', 'Récupère les notes à partir de l\'identifiant donné',[
            ['name'=>'identifiants','required'=>true,'description'=>'Identifiant séparés d\'un ; ex: 20170673;20172427','type'=>3],
        ]);
        return Command::SUCCESS;
    }
}
