---
paths:
  - 'app/Ai/Agents/*ResearchAgent.php'
---

# Agents

## Do not filter OpenAI preview web searches
Laravel AI 0.7.2 maps WebSearch to OpenAI `web_search_preview`, which rejects `filters.allowed_domains` with HTTP 400. Keep WebSearch.allowedDomains empty; pass preferred primary/media domains in the research prompt until the SDK maps this tool to `web_search`.
