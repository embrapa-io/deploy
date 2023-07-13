# Releaser

Script de deploy para ambientes remotos avulsos, ou seja, fora da rede de clusters do Embrapa I/O.

## Notes

```
docker service create --name registry --publish published=5000,target=5000 registry:2
```