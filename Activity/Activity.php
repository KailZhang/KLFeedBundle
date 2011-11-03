<?php

namespace KL\FeedBundle\Activity;

/**
 * Activity
 * 
 * the module is coupled with redis, for the sake of simpleness
 * 
 * @author Kail
 *
 */
abstract class Activity implements \Serializable
{
    /**
     * will be used to construct redis key
     * 
     * @var integer
     */
    private $id;
    
    /**
     * 
     * @var integer
     */
    private $type;
    
    /**
     * publisher does not need be activity's subject,
     * e.g. activities might be published by system
     * 
     * @var integer
     */
    protected $publisher;
    
    /**
     * 
     * 
     * @var array
     */
    protected $data;
    
    /**
     * timestamp
     * 
     * @var 
     */
    private $created_at;
    
    /**
     * Users that will be notified this activity
     * 
     * @var array
     */
    protected $subscribers;
    
    public function serialize()
    {
        $data_stream = array(
            'id' => $this->id,
            'type' => $this->type,
            'publisher' => $this->publisher,
            'created_at' => $this->created_at,
            'data' => $this->data,
            'subscribers' => $this->subscribers,
        );
        return serialize($data_stream);
    }
    
    public function unserialize($data)
    {
        $data_stream = unserialize($data);
        
        $this->id = $data_stream['id'];
        $this->type = $data_stream['type'];
        $this->publisher = $data_stream['publisher'];
        $this->created_at = $data_stream['created_at'];
        $this->data = $data_stream['data'];
        $this->subscribers = $data_stream['subscribers'];
    }
    
    public function getId()
    {
        return $this->id;
    }
    
    public function setId($id)
    {
        // ActivityManager will call this right before flush
        $this->id = $id;
    }
    
    public function getType()
    {
        return $this->type;
    }
    
    public function setType($type)
    {
        // ActivityManager will call this right before flush
        $this->type = $type;
    }
    
    public function getPublisher()
    {
        return $this->publisher;
    }
    
    public function setPublisher($publisher)
    {
        $this->publisher = $publisher;
    }
    
    public function getSubscribers()
    {
        return $this->subscribers;
    }
    
    public function setSubscribers($subscribers)
    {
        $this->subscribers = $subscribers;
    }
    
    public function getCreatedAt()
    {
        return $this->created_at;
    }
    
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
    }
    
    /**
     * Called on rendering in ActivityManager
     * you CAN update this on rendering if you really want
     * 
     * @return array
     */
    public function getData()
    {
    	return $this->data;
    }
    
    /**
     * default template that the activity will be rendered in,
     * follow symfony's routine like PaiqooFeedBundle:Activity:UpdateResidence.html.twig
     * 
     * @return string
     */
    public function getTemplate()
    {
        $cls_arr = explode('\\', get_class($this));
        $bundle_name = $cls_arr[0] . $cls_arr[1];
        $cls_name = array_pop($cls_arr);
        $cls_name = substr($cls_name, 0, strrpos($cls_name, 'Activity'));
        return "$bundle_name:Activity:$cls_name.html.twig";
    }
    
    /**
     * Generate activity key, which is holding the activity
     * It's generated from type, publisher, data(target), created_at etc
     * 
     * NOTE: generateKey is also used on activity that is unserialized from
     *       redis, so if you use any property rather than listed above. You
     *       need serialize it into data or overwrite serialize/unserialize
     * 
     * @return string
     */
    abstract public function generateKey();
}