<?php

namespace KL\FeedBundle\Activity;

interface MergableActivityInterface
{
    /**
     * Activities of same type, same merge clue will be merged
     * 
     * @return string
     */
    public function getMergeClue();
    
    /**
     * redis key holding keys of activities that will be merged
     * 
     * @param integer $subscriber
     * @return string
     */
    public function getActivityGroupRef($subscriber = null);
}