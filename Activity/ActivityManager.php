<?php

namespace KL\FeedBundle\Activity;

use Symfony\Component\DependencyInjection\ContainerInterface;
use KL\FeedBundle\User\UserManagerInterface;
use KL\FeedBundle\Event\PreSaveActivityEvent;

/**
 *
 * @todo decouple redis
 * @author Kail
 *
 */
class ActivityManager
{
    // private $activities; // @todo flush

    const ACTIVITY_ID = 'kl_activity:id';

    /**
     * THE service container
     *
     * @var ContainerInterface
     */
    private $container;

    /**
     * The redis client
     *
     */
    private $redis;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        // @todo make this configurable
        $this->redis = $this->container->get('snc_redis.default_client');
    }

    /**
     * Redis key whose value is list of user's subscribed activities
     *
     * @param integer $subscriber user id
     * @return string
     */
    public function getSubscribedFeedKey($subscriber)
    {
        return "sub:$subscriber:fd";
    }

    /**
     * Redis key whose value is list of user's published activities
     *
     * @param integer $publisher user id
     * @return string
     */
    public function getPublishedFeedKey($publisher)
    {
        return "pub:$publisher:fd";
    }

    // @todo from redis or from rdbms, or both

    /**
     * get list of activities the user published
     *
     * @param integer $user
     * @param integer $start
     * @param integer $nb
     */
    public function getFeedPublishedBy($user, $start = 0, $nb = 20)
    {
        $feed_key = $this->getPublishedFeedKey($user);
        $act_keys = $this->redis->lrange($feed_key, $start, $nb);
        $serialized_acts = $this->redis->mget($act_keys);
        $acts = array();
        foreach ($serialized_acts as $serialized_act) {
            $acts[] = unserialize($serialized_act);
        }

        return $acts;
    }

    /**
     * get list of activities the user subscribed
     *
     * @param integer $user
     * @param integer $start
     * @param integer $nb
     * @return array array of Activity objects
     */
    public function getFeedSubscribedBy($user, $start = 0, $nb = 20)
    {
        $feed_key = $this->getSubscribedFeedKey($user);
        $act_refs = $this->redis->lrange($feed_key, $start, $nb);
        $act_ref_types = $this->redis->pipeline(function($pipe) use ($act_refs) {
            foreach ($act_refs as $act_ref) {
                $pipe->type($act_ref);
            }
        }); // string or list

        // chances are $act_keys is 2-dimension array
        $act_keys = $this->redis->pipeline(function($pipe) use ($act_refs, $act_ref_types) {
            $index = 0;
            foreach ($act_refs as $act_ref) {
                if ($act_ref_types[$index] == 'list') {
                    $pipe->lrange($act_ref, 0, -1);
                } else {
                    $pipe->echo($act_ref);
                }
                ++$index;
            }
        });
        unset($act_refs);
        unset($act_ref_types);

        // chances are $serialized_acts is 2-dimension array
        $serialized_acts = $this->redis->pipeline(function($pipe) use ($act_keys) {
            foreach ($act_keys as $act_key) {
                if (is_array($act_key)) {
                    $pipe->mget($act_key);
                } else {
                    $pipe->get($act_key);
                }
            }
        });
        unset($act_keys);

        $acts = array();
        foreach ($serialized_acts as $serialized_act) {
            if (is_array($serialized_act)) {
                $act_grp = array();
                foreach ($serialized_act as $ref_sact) {
                    $act_grp[] = unserialize($ref_sact);
                }
                $acts[] = $act_grp;
            } else {
                $acts[] = unserialize($serialized_act);
            }
        }
        unset($serialized_acts);

        return $acts;
    }

    /**
     * Dispatch kl_feed.pre_save_activity event before save activity
     * 
     * Activities are stored as:
     * activity itself e.g.
     * act:2 => data
     *
     * user subscribed feed e.g.
     * sub:5:feed => act:11,act:8,act:{3:evt_5:111005},act:4,act:[2:u_2:111005],act:2
     *
     * user published feed e.g.
     * pub:3:feed => act:11,act:8,act:7,act:3,act:2
     *
     * @param Activity $act
     */
    public function save(Activity $act)
    {
    	if ($this->redis == null) {
    		// nothing can be done without redis, babe
    		return;
    	}
    	
        $act_id = $act->getId();
        if ($act_id == null) {
            $act_id = (int)$this->redis->get(self::ACTIVITY_ID);
            $act->setId($act_id);
        }
        if ($act->getType() == null) {
            $act_types = $this->container->getParameter('kl_feed.types');
            $cls_arr = explode('\\', get_class($act));
            $act_cls = array_pop($cls_arr);
            if (!in_array($act_cls, $act_types)) {
                throw new \Exception('Please add activity ' . $act_cls . ' to config.yml');
            }
            $flipped_types = array_flip($act_types);
            $act_type = $flipped_types[$act_cls];
            $act->setType($act_type);
        }
        if ($act->getPublisher() == null) {
            $userId = $this->container->get('security.context')->getToken()->getUser()->getId();
            $act->setPublisher($userId);
        }
        if ($act->getCreatedAt() == null) {
            $act->setCreatedAt(time());
        }

        // $act_types = $this->container->getParameter('kl_feed.types');
        
        $this->container->get('event_dispatcher')
                        ->dispatch('kl_feed.pre_save_activity', new PreSaveActivityEvent($act));

        $am = $this;
        $this->redis->pipeline(function($pipe) use ($act, $am) {
            $act_id = $act->getId();
            $act_key = "act:$act_id";
            $pipe->set($act_key, serialize($act));
            $pipe->incr(ActivityManager::ACTIVITY_ID);

            $publisher = $act->getPublisher();
            $pipe->lpush($am->getPublishedFeedKey($publisher), $act_key);

            $subscribers = $act->getSubscribers();
            if (empty($subscribers)) {
            	return;
            }
            if ($act instanceof ActionXYZActivity) {
                $actionXYZ_ref = $act->getActivityGroupRef();
                $pipe->lpush($actionXYZ_ref, $act_key);
                foreach ($subscribers as $subscriber) {
                    $feed_key = $am->getSubscribedFeedKey($subscriber);
                    $pipe->lrem($feed_key, 1, $actionXYZ_ref);
                    $pipe->lpush($feed_key, $actionXYZ_ref);
                }
            } else if ($act instanceof ABCActionActivity) {
                foreach ($subscribers as $subscriber) {
                    $feed_key = $am->getSubscribedFeedKey($subscriber);
                    $abcAction_ref = $act->getActivityGroupRef($subscriber);
                    $pipe->lpush($abcAction_ref, $act_key);

                    $pipe->lrem($feed_key, 1, $abcAction_ref);
                    $pipe->lpush($feed_key, $abcAction_ref);
                }
            } else {
                foreach ($subscribers as $subscriber) {
                    $feed_key = $am->getSubscribedFeedKey($subscriber);
                    $pipe->lpush($feed_key, $act_key);
                }
            }

            // @todo push subscribers to list activity:subscribers
        });
    }
    
    /**
     * Remove activity key from feed,
     * and remove activity itself
     * 
     * BEWARE, if time like date is taken into merge or key,
     * then the activity will not be deleted except its created
     * in the same time span
     * 
     * @param Activity $act
     */
    public function delete(Activity $act)
    {
        $actKey = $act->generateKey();
        $existAct = $this->redis->get($actKey);
        if ($existAct == null) {
            return;
        }
        
        $existAct = unserialize($existAct);
        $this->redis->pipeline(function($pipe) use ($actKey, $existAct) {
            $subscribers = $existAct->getSubscribers();
            foreach ($subscribers as $subscriber) {
                $feed_key = $am->getSubscribedFeedKey($subscriber);
                $pipe->lrem($feed_key, 1, $actKey);
            }
            $pipe->del($actKey);
        }
    }
    
    /**
     * Render activities, do merge if necessary
     * 
     * @param array $acts
     * @return array
     */
    public function renderActivities($acts)
    {
    	if (empty($acts)) return '';
    	
        $umId = $this->container->getParameter('kl_feed.usermanager_service');
        $um = $this->container->get($umId);
        if (!($um instanceof UserManagerInterface)) {
            throw new \Exception('UserManagerInterface must be implemented');
        }

        $uids = array();
        foreach ($acts as $act_grp) {
            if (is_array($act_grp)) {
                foreach ($act_grp as $act) {
                    $uids[] = $act->getPublisher();
                }
            } else {
                $uids[] = $act_grp->getPublisher();
            }
        }
        $uids = array_unique($uids);
        $allPublishers = $um->findUsersById($uids);
        
        $actRenderings = array();
        $tplVariables = array();
        foreach ($acts as $act_grp) {
        	$template = null;
        	if (is_array($act_grp)) {
        		$act1 = $act_grp[0];
        		$template = $act1->getTemplate();
        		if ($act1 instanceof ABCActionActivity) {
        			$publishers = array();
        			foreach ($act_grp as $act) {
        				$publishers[] = $allPublishers[$act->getPublisher()];
        			}
        			$tplVariables = array(
        			    'type'       => $act1->getType(),
        			    'publishers' => $publishers,
        			    'created_at' => $act1->getCreatedAt(),
        			    'target'     => $act1->getData(),
        			);
        		} else if ($act1 instanceof ActionXYZActivity) {
        			$targets = array();
        		    foreach ($act_grp as $act) {
                        $targets[] = $act->getData();
                    }
                    $tplVariables = array(
                        'type'       => $act1->getType(),
                        'publisher'  => $allPublishers[$act1->getPublisher()],
                        'created_at' => $act1->getCreatedAt(),
                        'targets'    => $targets,
                    );
        		}
        	} else {
                $act = $act_grp;
                $template = $act->getTemplate();
                $tplVariables = array(
                    'type'       => $act->getType(),
                    'publisher'  => $allPublishers[$act->getPublisher()],
                    'created_at' => $act->getCreatedAt(),
                    'target'     => $act->getData(),
                );
            }
        	
	        $actRenderings[] = $this->container->get('templating')->render(
	            $template,
	            $tplVariables
	        );
        }
        
        return $actRenderings;
    }
}