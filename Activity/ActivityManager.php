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
        $feedKey = $this->getPublishedFeedKey($user);
        $actKeys = $this->redis->lrange($feedKey, $start, $nb);
        $acts = array();
        if (!empty($actKeys)) {
            $serialized_acts = $this->redis->mget($actKeys);
            foreach ($serialized_acts as $serialized_act) {
                $acts[] = unserialize($serialized_act);
            }
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
        $feedKey = $this->getSubscribedFeedKey($user);
        $actRefs = $this->redis->lrange($feedKey, $start, $nb);
        $actRefTypes = $this->redis->pipeline(function($pipe) use ($actRefs) {
            foreach ($actRefs as $actRef) {
                $pipe->type($actRef);
            }
        }); // string or list
        
        // chances are $actKeys is 2-dimension array
        $actKeys = $this->redis->pipeline(function($pipe) use ($actRefs, $actRefTypes) {
            $index = 0;
            foreach ($actRefs as $actRef) {
                $actRefType = $actRefTypes[$index];
                if ($actRefType == 'list') {
                    $pipe->lrange($actRef, 0, -1);
                } else if ($actRefType == 'string') {
                    $pipe->echo($actRef);
                }
                ++$index;
            }
        });
        unset($actRefs);
        unset($actRefTypes);

        // chances are $serialized_acts is 2-dimension array
        $serialized_acts = $this->redis->pipeline(function($pipe) use ($actKeys) {
            foreach ($actKeys as $actKey) {
                if (is_array($actKey)) {
                    $pipe->mget($actKey);
                } else {
                    $pipe->get($actKey);
                }
            }
        });
        unset($actKeys);

        $acts = array();
        foreach ($serialized_acts as $serialized_act) {
            if (is_array($serialized_act)) {
                $actGrp = array();
                foreach ($serialized_act as $ref_sact) {
                    $actGrp[] = unserialize($ref_sact);
                }
                $acts[] = $actGrp;
            } else {
                $acts[] = unserialize($serialized_act);
            }
        }
        unset($serialized_acts);

        return $acts;
    }
    
    /**
     * 
     * @param string $actClsname
     * @return int
     */
    private function _getActivityType($actClsname)
    {
        $actTypes = $this->container->getParameter('kl_feed.types');
        $cls_arr = explode('\\', $actClsname);
        $actCls = array_pop($cls_arr);
        if (!in_array($actCls, $actTypes)) {
            throw new \Exception('Please add activity ' . $actCls . ' to config.yml');
        }
        $flippedTypes = array_flip($actTypes);
        $actType = (int)$flippedTypes[$actCls];
        
        return $actType;
    }
    
    /**
     * 
     * Arguments order is someone did something at sometime
     * NOW all activities will be patched date(ymd)
     * 
     * @param int $publisher
     * @param int|string $actTypeOrClsname Activity type or Activity class name
     * @param scalar $targetIdentifer
     * @param unknown $timeSpan NOT supported yet
     * @return string
     */
    public function generateActivityKey($publisher, $actTypeOrClsname, $targetIdentifier, $timeSpan = null)
    {
        $today = date('ymd', time());
        
        if (!is_int($actTypeOrClsname)) {
            $actType = $this->_getActivityType($actTypeOrClsname);
        } else {
            $actType = $actTypeOrClsname;
        }
        
        $actKey = "act[$publisher:$actType:$targetIdentifier:$today]";
        return $actKey;
    }
    
    /**
     * Convenience method
     * 
     * @param Activity $act
     */
    private function _generateActivityKey($act)
    {
        return $this->generateActivityKey($act->getPublisher(), get_class($act), $act->getTargetIdentifier());
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
    	
    	// $actId is not used any more...
        $actId = $act->getId();
        if ($actId == null) {
            $actId = (int)$this->redis->get(self::ACTIVITY_ID);
            $act->setId($actId);
        }
        if ($act->getType() == null) {
            $actType = $this->_getActivityType(get_class($act));
            $act->setType($actType);
        }
        if ($act->getPublisher() == null) {
            $userId = $this->container->get('security.context')->getToken()->getUser()->getId();
            $act->setPublisher($userId);
        }
        if ($act->getCreatedAt() == null) {
            $act->setCreatedAt(time());
        }

        // $actTypes = $this->container->getParameter('kl_feed.types');
        
        $eventDispatcher = $this->container->get('event_dispatcher');
        $eventDispatcher->dispatch('kl_feed.pre_save_activity', new PreSaveActivityEvent($act));
        
        // if actKey already exist, delete it first
        $actKey = $this->_generateActivityKey($act);
        // if ($this->redis->get($actKey) // delete will check whether it exists or not
        $this->delete($actKey);
        
        $am = $this;
        $this->redis->pipeline(function($pipe) use ($act, $actKey, $am) {
            $pipe->set($actKey, serialize($act));
            //$actId = $act->getId();
            //$actKeyId = "act:$actId";
            //$pipe->set($actKeyId, $actKey);
            $pipe->incr(ActivityManager::ACTIVITY_ID);

            $publisher = $act->getPublisher();
            $pipe->lpush($am->getPublishedFeedKey($publisher), $actKey);

            $subscribers = $act->getSubscribers();
            if (empty($subscribers)) {
            	return;
            }
            if ($act instanceof ActionXYZActivity) {
                $actionXYZ_ref = $act->getActivityGroupRef();
                $pipe->lpush($actionXYZ_ref, $actKey);
                foreach ($subscribers as $subscriber) {
                    $feedKey = $am->getSubscribedFeedKey($subscriber);
                    $pipe->lrem($feedKey, 1, $actionXYZ_ref);
                    $pipe->lpush($feedKey, $actionXYZ_ref);
                }
            } else if ($act instanceof ABCActionActivity) {
                foreach ($subscribers as $subscriber) {
                    $feedKey = $am->getSubscribedFeedKey($subscriber);
                    $abcAction_ref = $act->getActivityGroupRef($subscriber);
                    $pipe->lpush($abcAction_ref, $actKey);

                    $pipe->lrem($feedKey, 1, $abcAction_ref);
                    $pipe->lpush($feedKey, $abcAction_ref);
                }
            } else {
                foreach ($subscribers as $subscriber) {
                    $feedKey = $am->getSubscribedFeedKey($subscriber);
                    $pipe->lpush($feedKey, $actKey);
                }
            }
        });
    }
    
    /**
     * Remove activity key from feed,
     * and remove activity itself
     * 
     * !IMPORTANT, if time like date is taken into merge or key,
     * then the activity will not be deleted except its created
     * in the same time span
     * 
     * @param string|Activity $actKeyOrObj
     */
    public function delete($actKeyOrObj)
    {
        $actKey = null;
        if ($actKeyOrObj instanceof Activity) {
            $actKey = $this->_generateActivityKey($actKeyOrObj);
        } else {
            $actKey = $actKeyOrObj;
        }
        
        // $actKey = $act->generateKey();
        $existAct = $this->redis->get($actKey);
        if ($existAct == null) {
            return;
        }
        
        $am = $this;
        $existAct = unserialize($existAct);
        $this->redis->pipeline(function($pipe) use ($actKey, $existAct, $am) {
            $subscribers = $existAct->getSubscribers();
            if ($existAct instanceof ActionXYZActivity) {
                $actionXYZ_ref = $existAct->getActivityGroupRef();
                $pipe->lrem($actionXYZ_ref, 1, $actKey);
            } else if ($existAct instanceof ABCActionActivity) {
                foreach ($subscribers as $subscriber) {
                    $abcAction_ref = $existAct->getActivityGroupRef($subscriber);
                    $pipe->lrem($abcAction_ref, 1, $actKey);
                }
            } else {
                foreach ($subscribers as $subscriber) {
                    $feedKey = $am->getSubscribedFeedKey($subscriber);
                    $pipe->lrem($feedKey, 1, $actKey);
                }                
            }
            
            $pipe->del($actKey);
        });
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
        foreach ($acts as $actGrp) {
            if (is_array($actGrp)) {
                foreach ($actGrp as $act) {
                    $uids[] = $act->getPublisher();
                }
            } else {
                $uids[] = $actGrp->getPublisher();
            }
        }
        $uids = array_unique($uids);
        $allPublishers = $um->findUsersById($uids);
        
        $actRenderings = array();
        $tplVariables = array();
        foreach ($acts as $actGrp) {
        	$template = null;
        	if (is_array($actGrp)) {
        		$act1 = $actGrp[0];
        		$template = $act1->getTemplate();
        		if ($act1 instanceof ABCActionActivity) {
        			$publishers = array();
        			foreach ($actGrp as $act) {
        				$publishers[] = $allPublishers[$act->getPublisher()];
        			}
        			$tplVariables = array(
        			    'type'       => $act1->getType(),
        			    'publishers' => $publishers,
        			    'created_at' => $act1->getCreatedAt(),
        			    'target'     => $act1->getTarget(),
        			);
        		} else if ($act1 instanceof ActionXYZActivity) {
        			$targets = array();
        		    foreach ($actGrp as $act) {
                        $targets[] = $act->getTarget();
                    }
                    $tplVariables = array(
                        'type'       => $act1->getType(),
                        'publisher'  => $allPublishers[$act1->getPublisher()],
                        'created_at' => $act1->getCreatedAt(),
                        'targets'    => $targets,
                    );
        		}
        	} else {
                $act = $actGrp;
                $template = $act->getTemplate();
                $tplVariables = array(
                    'type'       => $act->getType(),
                    'publisher'  => $allPublishers[$act->getPublisher()],
                    'created_at' => $act->getCreatedAt(),
                    'target'     => $act->getTarget(),
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