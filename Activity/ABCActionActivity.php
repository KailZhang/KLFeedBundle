<?php

namespace KL\FeedBundle\Activity;

abstract class ABCActionActivity extends Activity implements MergableActivityInterface
{  
    public function getActivityGroupRef($subscriber = null)
    {
        $mergeClue = $this->getMergeClue();
        $type = $this->getType();
        $act_ref = "act#$type:$mergeClue#:$subscriber";
        
        return $act_ref;
    }
}