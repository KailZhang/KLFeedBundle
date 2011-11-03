<?php

namespace KL\FeedBundle\Activity;

abstract class ABCActionActivity extends Activity implements MergableActivityInterface
{  
    public function getActivityGroupRef($subscriber = null)
    {
        $mergeClue = $this->getMergeClue();
        $type = $this->getType();
        $act_ref = "act:[$type:$mergeClue]:$subscriber";
        
        return $act_ref;
    }
    /**
     * For ABCActionActivity, only publisher varies,
     * while publisher is known by activity, so the key
     * can be generated without no more knowledge
     * 
     */
    public function generateKey()
    {
        $mergeClue = $this->getMergeClue();
        $type = $this->getType();
        $publisher = $this->getPublisher();
        
        return "$publisher:$type:$mergeClue";
    }
}