<?php
namespace Azine\EmailBundle\Tests\Controller;
use Azine\EmailBundle\DependencyInjection\AzineEmailExtension;
use Azine\EmailBundle\Services\AzineEmailTwigExtension;
use Azine\EmailBundle\Services\Pagination;
use Azine\EmailBundle\Tests\FindInFileUtil;
use Azine\EmailBundle\Services\AzineTemplateProvider;
use Azine\EmailBundle\Entity\SentEmail;
use Azine\EmailBundle\Controller\AzineEmailTemplateController;
use Azine\PlatformBundle\Services\EmailTemplateProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\Client;
use Azine\EmailBundle\Tests\TestHelper;
use Doctrine\ORM\EntityManager;

class AzineEmailControllerTest extends WebTestCase
{
    public function testAdminEmailsDashboardAction()
    {
        $this->checkApplication();

        // Create a new client to browse the application
        $client = static::createClient();
        $client->followRedirects();

        $manager = $this->getEntityManager();
        $sentEmailRep = $manager->getRepository("Azine\EmailBundle\Entity\SentEmail");

        $testSentEmails = $sentEmailRep->search(['recipients' => TestHelper::TEST_EMAIL]);


        if (count($testSentEmails) == 0) {

            TestHelper::addSentEmails($manager);
            $testSentEmails = $sentEmailRep->search(['recipients' => TestHelper::TEST_EMAIL]);

        }

        $listUrl = substr($this->getRouter()->generate("azine_admin_emails_dashboard", array('_locale' => "en")), 13);
        $crawler = $this->loginUserIfRequired($client, $listUrl);

        //click on an email web view link to get to the web page
        $link = $crawler->filter(".sentEmail:contains('".EmailTemplateProvider::NEWSLETTER_TEMPLATE."')")->first()->filter("td")->last()->filter("a")->first()->link();
        $crawler = $client->click($link);

        $this->assertEquals(1, $crawler->filter("span:contains('_az.email.hello')")->count(), " div with hello message expected.");

        $crawler = $this->loginUserIfRequired($client, $listUrl);

        //Test filtering by email
        $crawler = $crawler->selectButton('sentEmail[save]');
        $form = $crawler->form();
        $form['sentEmail[recipients]'] = TestHelper::TEST_EMAIL;
        $crawler = $client->submit($form);

        $this->assertEquals($crawler->filter(".sentEmail")->count(), $crawler->filter("tr:contains('".TestHelper::TEST_EMAIL."')")->count(),"Table rows only with ".TestHelper::TEST_EMAIL." email are expected");


        //click on an email details view link to get to the details page
        $link = $crawler->filter(".sentEmail:contains('".TestHelper::TEST_EMAIL."')")->first()->filter("td")->last()->filter("a")->last()->link();
        $crawler = $client->click($link);

        $this->assertEquals(1, $crawler->filter("tr:contains('".TestHelper::TEST_EMAIL."')")->count(),"Table cell with email expected");

        $crawler = $this->loginUserIfRequired($client, $listUrl);

        $form['sentEmail[recipients]'] = '';
        $crawler = $client->submit($form);

        //Test filtering by token
        $form['sentEmail[token]'] = TestHelper::TEST_TOKEN;
        $crawler = $client->submit($form);

        $this->assertEquals($crawler->filter(".sentEmail")->count(), $crawler->filter(".sentEmail:contains('".TestHelper::TEST_TOKEN."')")->count(),"Table row only with ".TestHelper::TEST_TOKEN." token is expected");

    }

    /**
     * Load the url and login if required.
     * @param  string  $url
     * @param  string  $username
     * @param  Client  $client
     * @return Crawler $crawler of the page of the url or the page after the login
     */
    private function loginUserIfRequired(Client $client, $url, $username = "dominik", $password = "lkjlkjlkjlkj")
    {
        // try to get the url
        $client->followRedirects();
        $crawler = $client->request("GET", $url);

        $this->assertEquals(200, $client->getResponse()->getStatusCode(), "Status-Code 200 expected.");

        // if redirected to a login-page, login as admin-user
        if ($crawler->filter("input")->count() == 5 && $crawler->filter("#username")->count() == 1 && $crawler->filter("#password")->count() == 1 ) {

            // set the password of the admin
            $userProvider = $this->getContainer()->get('fos_user.user_provider.username_email');
            $user = $userProvider->loadUserByUsername($username);
            $user->setPlainPassword($password);
            $user->addRole("ROLE_ADMIN");

            $userManager = $this->getContainer()->get('fos_user.user_manager');
            $userManager->updateUser($user);

            $crawler = $crawler->filter("input[type='submit']");
            $form = $crawler->form();
            $form->get('_username')->setValue($username);
            $form->get('_password')->setValue($password);
            $crawler = $client->submit($form);
        }

        $this->assertEquals(200, $client->getResponse()->getStatusCode(),"Login failed.");
        $client->followRedirects(false);

        $this->assertStringEndsWith($url, $client->getRequest()->getUri(), "Login failed or not redirected to requested url: $url vs. ".$client->getRequest()->getUri());

        return $crawler;
    }

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @return UrlGeneratorInterface
     */
    private function getRouter()
    {
        return $this->getContainer()->get('router');
    }

    /**
     * Get the current container
     * @return \Symfony\Component\DependencyInjection\ContainerInterface
     */
    private function getContainer()
    {
        if ($this->container == null) {
            $this->container = static::$kernel->getContainer();
        }

        return $this->container;
    }

    /**
     * @return EntityManager
     */
    private function getEntityManager()
    {
        return $this->getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * Check if the current setup is a full application.
     * If not, mark the test as skipped else continue.
     */
    private function checkApplication()
    {
        try {
            static::$kernel = static::createKernel(array());
        } catch (\RuntimeException $ex) {
            $this->markTestSkipped("There does not seem to be a full application available (e.g. running tests on travis.org). So this test is skipped.");

            return;
        }
    }

}
