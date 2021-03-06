<?php
/**
 * AnimeDb package
 *
 * @package   AnimeDb
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2011, Peter Gribanov
 * @license   http://opensource.org/licenses/GPL-3.0 GPL v3
 */

namespace AnimeDb\Bundle\MyAnimeListSyncBundle\Event\Listener;

use AnimeDb\Bundle\CatalogBundle\Entity\Name;
use AnimeDb\Bundle\MyAnimeListSyncBundle\Service\Client;
use Doctrine\ORM\EntityManagerInterface;
use AnimeDb\Bundle\MyAnimeListSyncBundle\Repository\ItemRepository;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Symfony\Component\Templating\EngineInterface;
use AnimeDb\Bundle\CatalogBundle\Entity\Item as ItemCatalog;
use AnimeDb\Bundle\CatalogBundle\Entity\Source;
use AnimeDb\Bundle\AppBundle\Entity\Notice;
use AnimeDb\Bundle\MyAnimeListSyncBundle\Entity\Item as ItemMal;

/**
 * @package AnimeDb\Bundle\MyAnimeListSyncBundle\Event\Listener
 * @author  Peter Gribanov <info@peter-gribanov.ru>
 */
class ItemChangesListener
{
    /**
     * @var EngineInterface
     */
    protected $templating;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $host = '';
    /**
     * Sync the delete operation
     *
     * @var bool
     */
    protected $sync_remove = true;

    /**
     * Sync the insert operation
     *
     * @var bool
     */
    protected $sync_insert = true;

    /**
     * Sync the update operation
     *
     * @var bool
     */
    protected $sync_update = true;

    /**
     * @param EngineInterface $templating
     * @param Client $client
     * @param string $host
     * @param string $user_name
     * @param bool $sync_remove
     * @param bool $sync_insert
     * @param bool $sync_update
     */
    public function __construct(
        EngineInterface $templating,
        Client $client,
        $host,
        $user_name,
        $sync_remove,
        $sync_insert,
        $sync_update
    ) {
        $this->host = $host;
        $this->client = $client;
        $this->templating = $templating;

        if ($user_name) {
            $this->sync_remove = $sync_remove;
            $this->sync_insert = $sync_insert;
            $this->sync_update = $sync_update;
        } else {
            $this->sync_remove = $this->sync_insert = $this->sync_update = false;
        }
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof ItemCatalog && $this->sync_remove) {
            if ($id = $this->getId($entity, $args->getEntityManager())) {
                $this->client->sendAction(Client::ACTION_UPDATE, $id, $this->renderEntry($entity));
            } else {
                $notice = new Notice();
                $notice->setMessage($this->templating->render(
                    'AnimeDbMyAnimeListSyncBundle:Notice:failed_delete.html.twig',
                    ['item' => $entity]
                ));
                $args->getEntityManager()->persist($notice);
                $args->getEntityManager()->flush();
            }
        }
    }

    /**
     * Pre persist add item source if not exists
     *
     * @param LifecycleEventArgs $args
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof ItemCatalog && $this->sync_insert) {
            $this->addSource($entity, $args->getEntityManager());
        }
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof ItemCatalog && $this->sync_insert) {
            if ($id = $this->getId($entity, $args->getEntityManager())) {
                $this->client->sendAction(Client::ACTION_ADD, $id, $this->renderEntry($entity));
            } else {
                $notice = new Notice();
                $notice->setMessage($this->templating->render(
                    'AnimeDbMyAnimeListSyncBundle:Notice:failed_insert.html.twig',
                    ['item' => $entity]
                ));
                $args->getEntityManager()->persist($notice);
                $args->getEntityManager()->flush();
            }
        }
    }

    /**
     * Pre update add item source if not exists
     *
     * @param LifecycleEventArgs $args
     */
    public function preUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof ItemCatalog && $this->sync_update) {
            $this->addSource($entity, $args->getEntityManager());
        }
    }

    /**
     * @param ItemCatalog $entity
     * @param EntityManagerInterface $em
     */
    protected function addSource(ItemCatalog $entity, EntityManagerInterface $em)
    {
        if (!$this->getId($entity, $em) && ($id = $this->findIdForItem($entity))) {
            $source = (new Source())->setUrl($this->host.'anime/'.$id.'/');
            $entity->addSource($source);

            $em->persist($source);
            $em->flush();
        }
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof ItemCatalog && $this->sync_update) {
            /* @var $rep ItemRepository */
            $rep = $args->getEntityManager()->getRepository('AnimeDbMyAnimeListSyncBundle:Item');
            /* @var $mal_item ItemCatalog */
            $mal_item = $rep->findByCatalogItem($entity);

            if ($mal_item instanceof ItemMal) {
                $this->client->sendAction(Client::ACTION_UPDATE, $mal_item->getId(), $this->renderEntry($entity));

            } elseif ($id = $this->getId($entity, $args->getEntityManager())) {
                $this->client->sendAction(Client::ACTION_ADD, $id, $this->renderEntry($entity));

            } else {
                $notice = new Notice();
                $notice->setMessage($this->templating->render(
                    'AnimeDbMyAnimeListSyncBundle:Notice:failed_update.html.twig',
                    ['item' => $entity]
                ));
                $args->getEntityManager()->persist($notice);
                $args->getEntityManager()->flush();
            }
        }
    }

    /**
     * Get MyAnimeList id for item
     *
     * @param ItemCatalog $entity
     * @param EntityManagerInterface $em
     *
     * @return int
     */
    protected function getId(ItemCatalog $entity, EntityManagerInterface $em)
    {
        // search in sources
        /* @var $source Source */
        foreach ($entity->getSources() as $source) {
            if (strpos($source->getUrl(), $this->host) === 0) {
                if (preg_match('#/(\d+)/#', $source->getUrl(), $mat)) {
                    return $mat[1];
                }
                break;
            }
        }

        /* @var $rep ItemRepository */
        $rep = $em->getRepository('AnimeDbMyAnimeListSyncBundle:Item');
        // get MyAnimeList item link
        $mal_item = $rep->findByCatalogItem($entity);

        if ($mal_item instanceof ItemMal) {
            return $mal_item->getId();
        }

        return 0;
    }

    /**
     * Try to find the MyAnimeList id for the item
     *
     * @param ItemCatalog $item
     *
     * @return int|null
     */
    protected function findIdForItem(ItemCatalog $item)
    {
        // find name for search
        $query = '';
        if (preg_match('/[a-z]+/i', $item->getName())) {
            $query = $item->getName();
        } else {
            /* @var $name Name */
            foreach ($item->getNames() as $name) {
                if (preg_match('/[a-z]+/i', $name->getName())) {
                    $query = $name->getName();
                    break;
                }
            }
        }

        // try search
        return $query ? $this->client->search($query) : null;
    }

    /**
     * @param ItemCatalog $entity
     *
     * @return string
     */
    protected function renderEntry(ItemCatalog $entity)
    {
        return $this->templating->render(
            'AnimeDbMyAnimeListSyncBundle::entry.xml.twig',
            ['item' => $entity]
        );
    }
}
