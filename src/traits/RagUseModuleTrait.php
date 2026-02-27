<?php
namespace app\modules\neuron\traits;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

trait RagUseModuleTrait {

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->config->getEmbeddingProvider();
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return $this->config->getVectorStore();
    }
}