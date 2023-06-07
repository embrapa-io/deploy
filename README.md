# Releaser

Script de deploy para ambientes remotos avulsos, ou seja, fora da rede de clusters do Embrapa I/O.

## Configuração

Crie uma chave SSH para permitir acesso aos seus projetos no [GitLab do Embrapa I/O](https://git.embrapa.io):

```
ssh-keygen -o -t rsa -b 4096 -C "" -f ./ssh
```

> **Atenção!** É imprescindível que a **senha da chave seja deixada em branco**.

O comando irá gerar um arquivo `ssh` (chave privada) e outro denominado `ssh.pub` (chave pública).

Logue no GitLab com seu usuário. Acesse as configurações e, em seguida, clique na opção "*SSH Keys*". Na seção **Add an SSH Key**, copie e cole no campo **Key** o conteúdo da **chave SSH pública** (`ssh.pub`). Utilize o campo **Title** para atribuir uma informação relevante (p.e., "Releaser no servidor foo.bar.lee"). Por fim, clique em **Add key**.

Será necessário também cadastrar uma **Personal Access Token**, na seção homônima nas configurações do seu perfil no [GitLab](https://git.embrapa.io). Em _token name_, coloque uma informação relevante (p.e., "Releaser no servidor foo.bar.lee"). Habilite como escopo a opção "**read_api**".
