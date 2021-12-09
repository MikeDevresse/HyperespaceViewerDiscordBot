<?php

namespace App\Command;

use App\Entity\Student;
use Discord\Discord;
use Discord\Slash\Client;
use Discord\Slash\Parts\Choices;
use Discord\Slash\Parts\Interaction;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
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
    private EntityManagerInterface $em;

    public function __construct(string $botPublicKey, string $botToken, HttpClientInterface $httpClient, EntityManagerInterface $em)
    {
        parent::__construct(null);
        $this->botPublicKey = $botPublicKey;
        $this->botToken = $botToken;
        $this->httpClient = $httpClient;
        $this->em = $em;
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

        $client->registerCommand('notes', function (Interaction $interaction, Choices $choices) {
            $student = $this->em->getRepository(Student::class)->findOneBy(['login'=>$choices->identifiant]);
            if(!$student) {
                $interaction->replyWithSource('Identifiant introuvable, veuillez d\'abord inscire votre user sur https://hyperespace.mikedevresse.fr', embeds: []);
            }
            else {
                $data = $this->getUsers($student->getLogin(),$student->getPassword());
                if($data === null) {
                    $interaction->replyWithSource('Identifiant introuvable, veuillez d\'abord inscire votre user sur https://hyperespace.mikedevresse.fr', embeds: []);
                }
                else {
                    $interaction->replyWithSource($this->format($data), embeds: []);
                }
            }

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
╔════════╤════════╤════════╤════════╤════════╤════════╤════════╤════════╗
║ D1     │ D2     │ D3     │ D4     │ D5     │ D6     │ Total  │ Acquis ║
╠════════╪════════╪════════╪════════╪════════╪════════╪════════╪════════╣')."\n";

        foreach ($data as $userData) {
            $ret .= sprintf(
                '║ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %5.5s%% │ %6.6s ║'."\n",
                $userData['d1'],$userData['d2'],$userData['d3'],$userData['d4'],$userData['d5'],$userData['d6'],$userData['total'],$userData['acquiredDomains'].'/6'
            );
            if($userData === end($data)) $ret .= sprintf('╚════════╧════════╧════════╧════════╧════════╧════════╧════════╧════════╝'."\n");
            else $ret .= sprintf('╟────────┼────────┼────────┼────────┼────────┼────────┼────────┼────────╢'."\n");
        }
        return $ret.'`';
    }

    public function getUsers(string $username, string $password): ?array {

        $jar = new CookieJar();
        $client = new HttpClient(['base_uri'=>'https://cas.univ-lehavre.fr','cookies'=>$jar]);
        $resp = $client->get('/cas/login');
        $crawler = new Crawler($resp->getBody()->getContents());
        $token = $crawler->filter('input[name="lt"]')->attr('value');
        $execution = $crawler->filter('input[name="execution"]')->attr('value');
        $resp = $client->request('POST','/cas//login',[
            'allow_redirects' => false,
            'body' => 'username='.$username.'&password='.$password.'&lt='.$token.'&execution='.$execution.'&_eventId=submit',
            'headers' => ['Content-type' => 'application/x-www-form-urlencoded'],
            'cookies' => $jar
        ]);
        if(count($jar->toArray()) < 3 )
        {
            return null;
        }
        $resp = $client->request('GET', 'https://www-apps.univ-lehavre.fr/hyperespace/', [
            'cookies' => $jar,
            'allow_redirects' => [
                'max' => 10
            ]
        ]);
        $crawler = new Crawler($resp->getBody()->getContents());
        $cont = false;
        $notes = [
            'D1' => ['total' => 0, 'validated' => 0, 'not-validated' => 0],
            'D2' => ['total' => 0, 'validated' => 0, 'not-validated' => 0],
            'D3' => ['total' => 0, 'validated' => 0, 'not-validated' => 0],
            'D4' => ['total' => 0, 'validated' => 0, 'not-validated' => 0],
            'D5' => ['total' => 0, 'validated' => 0, 'not-validated' => 0],
            'D6' => ['total' => 0, 'validated' => 0, 'not-validated' => 0],
        ];
        $curDomain = null;
        $crawler->filter('table tbody tr, h3')->each(function (Crawler $node, $i) use (&$cont, &$notes, &$curDomain) {
            if(str_starts_with($node->text(),'M2')) {
                $cont = true;
            }
            elseif(str_starts_with($node->text(),'M1')) {
                $cont = false;
            }
            if($cont) {
                $node->filter('th,td')->each(function (Crawler $node, $i) use (&$notes, &$curDomain) {
                    if(preg_match('/^D[0-9]$/', $node->text()) === 1) {
                        $curDomain = $node->text();
                    }
                    if($curDomain !== null && $node->text() === '✓') {
                        $notes[$curDomain]['validated']++;
                        $notes[$curDomain]['total']++;
                    }
                    if($curDomain !== null && $node->text() === '✗') {
                        $notes[$curDomain]['not-validated']++;
                        $notes[$curDomain]['total']++;
                    }
                });
            }
        });
        $ret = [
            'fullName' => $username,
            'd1' => '0',
            'd2' => '0',
            'd3' => '0',
            'd4' => '0',
            'd5' => '0',
            'd6' => '0'
        ];
        $total = 0;
        $validated = 0;
        $acquired = 0;
        for($i = 1 ; $i<=6 ; $i++) {
            $ret['d'.$i] = round(100 * $notes['D'.$i]['validated'] / max($notes['D'.$i]['total'],1), 2);
            if($ret['d'.$i] > 66.666) {
                $acquired++;
            }
            $total += $notes['D'.$i]['total'];
            $validated += $notes['D'.$i]['validated'];
        }
        $ret['total'] = round(100*$validated/max($total,1),2);
        $ret['acquiredDomains'] = $acquired;
        return [$ret];
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
