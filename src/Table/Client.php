<?php

namespace Jefyokta\Mqttbroker\Table;

class Client
{
    public function __construct(
        private readonly string $key,
    ) {}

    private function getTopics(): array
    {
        $data = Provider::raw()?->get($this->key);
        if (!$data || empty($data['topics'])) return [];
        return json_decode($data['topics'], true) ?? [];
    }
    public function inTopic(string $topic): bool
    {

        return in_array($topic, $this->getAllTopics());
    }

    private function setTopics(array $topics): void
    {
        $data = Provider::raw()?->get($this->key);
        if ($data) {
            $data['topics'] = json_encode(array_values(array_unique($topics)));
            Provider::raw()?->set($this->key, $data);
        } else {
            Provider::raw()?->set($this->key, ['topics' => json_encode($topics), 'fd_type' => ""]);
        }
    }

    public function addTopic(string $topic): void
    {
        $topics = $this->getTopics();
        if (!in_array($topic, $topics)) {
            $topics[] = $topic;
            $this->setTopics($topics);
        }
    }

    public function addTopics(array $topicsToAdd): void
    {
        $topics = $this->getTopics();
        $topics = array_unique(array_merge($topics, $topicsToAdd));
        $this->setTopics($topics);
    }

    public function removeTopic(string $topic): void
    {
        $topics = $this->getTopics();
        $topics = array_filter($topics, fn($t) => $t !== $topic);
        $this->setTopics(array_values($topics));
    }
    /**
     * 
     * return all of client's topic(s)
     * @return string[]
     */
    public function getAllTopics(): array
    {
        return $this->getTopics();
    }

    public function exit()
    {
        Provider::raw()->del($this->key);
    }
    function getFd(): int
    {
        return explode(":", $this->key)[1];
    }
}
