<?php

namespace App\Command;

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Embed\Field;
use Discord\Parts\Part;
use Discord\Slash\Client;
use Discord\Slash\Parts\Choices;
use Discord\Slash\Parts\Interaction;
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
    name: 'bot:run',
    description: 'Runs the bot',
)]
class BotRunCommand extends Command
{
    private string $botPublicKey;
    private string $botToken;
    private HttpClientInterface $httpClient;

    public function __construct(string $botPublicKey, string $botToken, HttpClientInterface $httpClient)
    {
        parent::__construct(null);
        $this->botPublicKey = $botPublicKey;
        $this->botToken = $botToken;
        $this->httpClient = $httpClient;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $discord = new Discord(['token'=>$this->botToken]);
        $client = new Client([
            'public_key' => $this->botPublicKey,
            'token' => $this->botToken,
            'application_id' => '854690077199040532',
            'loop'=> $discord->getLoop(),
        ]);

        $client->linkDiscord($discord,true);

        $client->registerCommand('top', function (Interaction $interaction, Choices $choices) use ($discord) {
            $data = $this->getUsers(null,$choices->page ?? 1,$perPage = min($choices->per_page ?? 5, 8));

            if($data == null) $interaction->replyWithSource('Il y à eu une erreur avec votre requête');
            else              $interaction->replyWithSource($this->format($data['hydra:member'],ceil($data['hydra:totalItems']/$perPage),$choices->page ?? 1));

            $interaction->acknowledge();
        });

        $client->registerCommand('notes', function (Interaction $interaction, Choices $choices) use ($discord) {
            $data = $this->getUsers(explode(';',$choices->identifiants));

            if($data == null) $interaction->replyWithSource('Il y à eu une erreur avec votre requête');
            else              $interaction->replyWithSource($this->format($data['hydra:member']));

            $interaction->acknowledge();
        });

        $discord->run();
        $client->run();
        return Command::SUCCESS;
    }

    public function format($data, $nbPage = null, $curPage= null): string {
        $longestName = 0;
        foreach ($data as $userData) {
            if(strlen($userData['fullName']) > $longestName) $longestName = strlen($userData['fullName']);
        }
        $ret = sprintf('`'.($nbPage==null?'Notes':'Page '.$curPage.'/'.$nbPage).':
╔═%s═╤════════╤════════╤════════╤════════╤════════╤════════╤════════╤════════╗
║ %s │ D1     │ D2     │ D3     │ D4     │ D5     │ D6     │ Total  │ Acquis ║
╠═%s═╪════════╪════════╪════════╪════════╪════════╪════════╪════════╪════════╣',str_repeat('═', $longestName+4),str_repeat(' ', $longestName+4),str_repeat('═', $longestName+4))."\n";

        foreach ($data as $userData) {
            $ret .= sprintf(
                '║ %-'.$longestName.'.'.$longestName.'s #%-2.2s │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %6.6s ║'."\n",
                $this->removeAccent($userData['fullName']),$userData['top'],$userData['d1'],$userData['d2'],$userData['d3'],$userData['d4'],$userData['d5'],$userData['d6'],$userData['total'],$userData['acquieredDomains'].'/6'
            );
            if($userData == end($data)) $ret .= sprintf('╚═%s═╧════════╧════════╧════════╧════════╧════════╧════════╧════════╧════════╝'."\n",str_repeat('═', $longestName+4));
            else $ret .= sprintf('╟─%s─┼────────┼────────┼────────┼────────┼────────┼────────┼────────┼────────╢'."\n",str_repeat('─', $longestName+4));
        }
        return $ret.'`';
    }

    public function getUsers(array $identifiers = null, int $page = 1, int $items = 10): ?array {
        try {
            if($identifiers == null) {
                $response = $this->httpClient->request('GET', "https://hyperespace.mikedevresse.fr/api/students?page=$page&itemsPerPage=$items", [
                    'headers' => ['Accept'=>'application/ld+json']
                ]);
                $data = json_decode($response->getContent(),true);
                foreach ($data['hydra:member'] as $k=>$uData) {
                    $data['hydra:member'][$k]['top'] = ($page-1)*$items+$k+1;
                }
                return $data;
            }
            else {
                $response = $this->httpClient->request('GET', 'https://hyperespace.mikedevresse.fr/api/students', [
                    'headers' => ['Accept'=>'application/ld+json']
                ]);
                $data = json_decode($response->getContent(),true);
                $count = 0;
                foreach ($data['hydra:member'] as $k=>$uData) {
                    if(in_array($uData['number'], $identifiers)) {
                        $count++;
                        if(($page-1)*$items < $count and $count <= $page*$items) {
                            $data['hydra:member'][$k]['top'] = $k+1;
                        }
                        else unset($data['hydra:member'][$k]);
                    }
                    else unset($data['hydra:member'][$k]);
                }
                return $data;
            }
        } catch (\Exception $e) { return null; }
    }

    function removeAccent ($string) {
        $table = array(
            'Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z', 'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c',
            'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
            'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss',
            'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e',
            'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o',
            'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y',
            'Ŕ'=>'R', 'ŕ'=>'r',
        );

        return strtr($string, $table);
    }
}
