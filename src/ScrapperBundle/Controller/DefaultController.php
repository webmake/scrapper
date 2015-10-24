<?php

namespace ScrapperBundle\Controller;

use Doctrine\ORM\EntityManager;
use Goutte\Client;
use Monolog\Logger;
use ScrapperBundle\Entity\Dream;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DomCrawler\Crawler;

class DefaultController extends Controller
{
    /** @var bool */
    private $streamOpen = false;
    /** @var bool */
    private $streamValid = false;
    /** @var Logger */
    private $logger;
    /** @var array */
    private $context = ['scrapper'];
    /** @var EntityManager */
    private $em;

    public function indexAction()
    {
        $this->em = $this->getDoctrine()->getManager();
        $this->logger = $this->get('logger');
        $this->parsePage();
        return $this->render('ScrapperBundle:Default:index.html.twig');
    }

    protected function parsePage()
    {
        $uri = $this->getParameter('scrape_uri');
        $client = new Client();
        $this->logger->info('Getting ' . $uri, $this->context);
        $crawler = $client->request('GET', $uri);
        $this->logger->info('Got', $this->context);
        $crawler->filter('#body td center>a')->each($this->parseLetters());
    }

    /**
     * @return \Closure
     */
    private function parseLetters()
    {
        return function (Crawler $node) {
            $client = new Client();
            $uri = $node->link()->getUri();
            $this->logger->info('Getting ' . $uri, $this->context);
            $crawler = $client->request('GET', $uri);
            $this->logger->info('Got', $this->context);
            $crawler->filter('#body table table b>a')->each($this->parseWords());
        };
    }

    /**
     * @return \Closure
     */
    private function parseWords()
    {
        return function (Crawler $node) {
            $client = new Client();
            $uri = $node->link()->getUri();
            $this->logger->info('Getting ' . $uri, $this->context);
            $crawler = $client->request('GET', $uri);
            $this->logger->info('Got', $this->context);
            $crawler->filter('#body table .inner')->each($this->parseMeanings());
        };
    }

    /**
     * @return \Closure
     */
    private function parseMeanings()
    {
        return function (Crawler $node) {
            $this->logger->info('Parsing meaning', $this->context);
            $content = $this->getContent($node);

            if (preg_match('#\<b\>(.*?)\<\/b\>#', $content, $matches)) {
                $this->saveEntity($matches[1], $content);
            } else {
                $this->logger->error('Parse failure: didn\'t found the word', $this->context);
            }
        };
    }

    /**
     * @param Crawler $node
     * @return string
     */
    private function getContent(Crawler $node)
    {
        $content = '';
        foreach ($node as $td) {
            $this->streamValid = false;
            $this->streamOpen = true;
            foreach ($td->childNodes as $child) {
                if (!$this->streamValid && $child instanceof \DOMText && $child->nodeName == '#text') {
                    continue;
                } elseif (!$this->streamValid && $child instanceof \DOMElement && $child->tagName != 'center') {
                    continue;
                } elseif (!$this->streamValid && $child instanceof \DOMElement && $child->tagName == 'center') {
                    $this->streamValid = true;
                    continue;
                } elseif ($this->streamValid && $child instanceof \DOMElement && $child->tagName == 'center') {
                    $this->streamValid = false;
                    $this->streamOpen = false;
                    continue;
                }
                if ($this->streamOpen && $this->streamValid) {
                    $content .= $child->ownerDocument->saveHTML($child);
                }
            }
        }
        return $content;
    }

    /**
     * @param string $matches
     * @param string $content
     */
    private function saveEntity($matches, $content)
    {
        $entity = new Dream();
        $entity->setWord($matches)->setMeaning($content);
        $this->em->persist($entity);
        $this->em->flush();
    }
}
