<?php

namespace KL\FeedBundle\Activity;

abstract class ActionXYZActivity extends Activity implements MergableActivityInterface
{
    public function getActivityGroupRef($subscriber = null)
    {
        $inv_id = $this->getInvariantIdentifier();
        $today = date('ymd', time());
        $ref_str = "$inv_id:$today";
        $type = $this->getType();
        $act_ref = "act:[$type:$ref_str]";
        
        return $act_ref;
    }
}