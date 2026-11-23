# Sécurité — SideKey

Deux documents, produits pour le critère d'acceptation du ticket **C-03** (« critères DICP et security patterns retenus documentés ») :

- [`dicp.md`](dicp.md) — analyse DICP : biens et propriétaires, analyse par critère, matrice de synthèse, scénarios de menace, risque résiduel accepté, traçabilité ENF / tests / tickets.
- [`security-patterns.md`](security-patterns.md) — patrons de sécurité et patrons métier retenus, défense en profondeur, mapping OWASP Top 10 (2021), anti-patrons évités, limites assumées.

Ils complètent les décisions d'architecture : les ADR [`0003`](../adr/) (authentification JWT et jeton de rafraîchissement), [`0004`](../adr/) (chiffrement des clés de licence) et [`0005`](../adr/) (hébergement dans l'Union européenne) motivent les choix ; ces deux documents en tirent les exigences, les contrôles et les tests. Le DICP fixe le *pourquoi* et le niveau d'exigence, les patrons fixent le *comment* et la couche qui porte chaque contrôle. Les deux renvoient aux tickets du [backlog](../backlog/backlog_SideKey.md) qui les livrent.
