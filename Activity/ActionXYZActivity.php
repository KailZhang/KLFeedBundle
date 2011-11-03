<?php

namespace KL\FeedBundle\Activity;

abstract class ActionXYZActivity extends Activity implements MergableActivityInterface
{
    public function getActivityGroupRef($subscriber = null)
    {
        $mergeClue = $this->getMergeClue();
        $type = $this->getType();
        $act_ref = "act:[$type:$mergeClue]";
        
        return $act_ref;
    }
}