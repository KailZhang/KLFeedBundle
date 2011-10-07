<?php

namespace KL\FeedBundle\Activity;

interface MergableActivityInterface
{
    /**
     * string that can identify the invariant part of the activity, e.g.
     * AAction(Type) of AActionXYZ, Action(Type)X of ABCActionX,
     * and what will be merged are variable parts
     * 
     * @return string
     */
    public function getInvariantIdentifier();
    
    /**
     * redis key holding keys of activities that will be merged
     * 
     * @param integer $subscriber
     * @return string
     */
    public function getActivityGroupRef($subscriber = null);
}