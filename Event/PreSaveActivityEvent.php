<?php

namespace KL\FeedBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use KL\FeedBundle\Activity\Activity;

class PreSaveActivityEvent extends Event
{
	private $activity;
	
	public function __construct(Activity $activity)
	{
		$this->activity = $activity;
	}
	
	public function getActivity()
	{
		return $this->activity;
	}
}