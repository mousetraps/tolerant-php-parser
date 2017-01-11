# How it Works

## Lexer
The lexer produces tokens out PHP, based on the following lexical grammar:
* https://github.com/php/php-langspec/blob/master/spec/19-grammar.md
* http://php.net/manual/en/tokens.php

Initially, we tried handspinning the lexer, rather than using PHP's built-in 
[`token_get_all`](http://php.net/manual/en/function.token-get-all.php), because that approach would provide the most 
flexibility to use our own lightweight token representation (see below) from the beginning, rather than requiring
a conversion. This initial implementation is available in `src/Lexer.php`, but has been deprecated in favor of 
`src/PhpTokenizer.php`.

Ultimately, the biggest challenge with the initial approach was performance (especially with Unicode representations). Ultimately,
we found that PHP doesn't provide an efficient way to extract character codes without multiple conversions after the initial
file-read. 

> **"Model" vs "Representation"**
> * Model := general information exposed, how we will intaract with it
> * Representation := underlying data structures


### Tokens (Model)
Tokens take the following form:
```
Token: {
    Kind: Id, // the classification of the token
    FullStart: 0, // the start of the token, including trivia
    Start: 3, // the start of the token, excluding trivia
    Length: 6 // the length of the token (from FullStart)
}
```

### Tokens (Representation)
#### Helper functions
In order to be as efficient as possible, we do not store full content in memory.
Instead, each token is uniquely defined by four integers, and we take advantage of helper
functions to extract further information.
* `GetTriviaForToken`
* `GetFullTextForToken`
* `GetTextForToken`

#### Data structures
At this point in time, the Representation has not yet diverged from the Model. Tokens
are currently represented as a `Token` object, with four properties - `$kind`, `$fullStart`,
`$start`, and `$length`. However, objects (and arrays, and ...) are super expensive in PHP
(see `experiments/php7-types.txt`). WordPress (11MB source) has around ~1 million tokens (more if
you do no conversion from `token_get_all`, so it gets really expensive, really fast with an object
representation. Ultimately, we need a way to store these four properties in a performant and memory-efficient
manner. 

We've explored a number of different options at this point.
* objects / array / SplFixedArray / SplFixedArray::fromArray
  * pros: friendly API
  * cons: as stated above, major overhead.
* pack/unpack to pack the token as ints into a string.
  * pros: memory efficient (~62 bytes per string)
  * cons: requires a PHP complex property, and packing/unpacking adds ~10x overhead to access all properties.
* structs in a native PHP extension
  * pros: as memory-efficient as you can get
  * cons: more complicated than using only PHP, ~3.5x overhead to access properties
* store info in numeric properties using bitwise-operators
  * pros: memory-efficient (gets rid of Token objects altogether), no siginificant overhead for property access
  * cons: more complicated encoding

It has not yet been implemented, and we're very much open to other ideas, but at the moment we're currently
leaning towards bitwise operators. So now the question is "what encoding?" - note that we can always drop 
the `Start` property, and recompute, but we would prefer not to throw away that information if we can help it. 

Each double/long property in PHP is 16 bytes total, though only 4-8 bytes (depending on 32-bit or 64-bit) are
used for the value. We propose the following 64-bit representation (1 16-byte property on a 64-bit machine, two
12-byte properties on a 32-bit machine):
```
bits 1-8: TokenKind
bits 9-32: FullStart
bits: 33-59 - (TriviaLength << LengthEncoding) & TokenLength
bits: 60-64: LengthEncoding
```

`TokenKind` and `FullStart` remain unchanged, so we won't dive into that, but the second pair needs
some explanation. Instead of storing `Start` and `Length` properties, we will store `TriviaLength` 
(`FullStart - Start`) and `TokenLength` (`Length - TriviaLength`). Because we don't know which of the two
 will be longer (`TriviaLength` or `TokenLength`), we won't know how many bytes to allocate to each until
 we tokenize the stream. Therefore the bits allocated to `LengthEncoding` specify the bitshift value.

In the edge cases where we cannot store both, and we can recompute the value. Note that this extra complexity
does not preclude us from presenting a more reasonable API for consumers of the API because we can simply
override the property getters / setters on Node.


### Invariants
In order to ensure that the parser evolves in a healthy manner over time, 
we define and continuously test the set of invariants defined below:
* The sum of the lengths of all of the tokens is equivalent to the length of the document
* The Start of every token is always greater than or equal to the FullStart of every token.
* A token's content exactly matches the range of the file its span specifies.
* `GetTriviaForToken` + `GetTextForToken` == `GetFullTextForToken`
* concatenating `GetFullTextForToken` for each token returns the document
* `GetTriviaForToken` returns a string of length equivalent to `(Start - FullStart)`
* `GetFullTextForToken` returns a string of length equivalent to `Length`
* `GetTextForToken` returns a string of length equivalent to `Length - (Start - FullStart)`
* See the code for an up-to-date list...

## Parser
### Node (Model)
Nodes include the following information:
```
Node: {
  Kind: Id,
  Parent: ParentNode,
  Children: List<Node|Token>
}
```

### Node (Representation)
> TODO - discerning between Model and Representation

### Abstract Syntax Tree
An example tree is below. The tree Nodes (represented by circles), and Tokens (represented by squares)
![image](https://cloud.githubusercontent.com/assets/762848/19092929/e10e60aa-8a3d-11e6-8b90-51eabe5d1d8e.png)

Below, we define a set of invariants. This set of invariants provides a consistent foundation that makes it
easier to confidently reason about the tree as we continue to build up our understanding.

For instance, the following properties hold true about every Node (N) and Token (T).
```
POS(N) -> POS(FirstChild(N))
POS(T) -> T.Start
WIDTH(N) -> SUM(Child_i(N))
WIDTH(T) -> T.Width
```


### Invariants
* Invariants for all Tokens hold true 
* The tree contains every token
* span of any node is sum of spans of child nodes and tokens
* The tree length exactly matches the file length
* Every leaf node of the tree is a token
* Every Node contains at least one Token

### Building up the Tree

#### Error Tokens
We define two types of `Error` tokens:
* **Skipped Tokens:** extra token that no one knows how to deal with
* **Missing Tokens:** Grammar expects a token to be there, but it does not exist

##### Example 1
Let's say we run the following through `parseIf`
```php
if ($expression) 
{
}
```

```php
function parseIf($str, $parent) {
    $n = new IfNode();
    $n->ifKeyword = eat("if");
    $n->openParen = eat("(");
    $n->expression = parseExpression();
    $n->closeParen = eat(")");
    $n->block = parseBlock();
    $n->parent = $parent;
}
```

This above should generate the `IfNode` successfully. But let's say we run the following through,
which is missing a close paren token. 
```php
if ($expression // ) <- MissingToken
{
}
```

In this case, `eat(")")` will generate a `MissingToken` because the grammar expects a
token to  be there, but it does not exist.

##### Example 2
```php
class A {
    function foo() {
        return;
 // } <- MissingToken

    public function bar() {

    }
}
```

In this case, the `foo` function block is not closed. A `MissingToken` will be similarly generated,
but the logic will be a little different, in order to provide a gracefully degrading experience.
In particular, the tree that we expect here looks something like this:

![image](https://cloud.githubusercontent.com/assets/762848/19094553/727fd634-8a45-11e6-9491-97f3a6b9a35e.png)

This is achieved by continually keeping track of the current `ParseContext`. That is to say,
every time we venture into a child, that child is aware of its parent. Whenever the child gets to a token
that they themselves don't know how to handle (e.g. a `MethodNode` doesn't know what `public` means), they ask their parent if they know how to handle it, and 
continue walking up the tree. If we've walked the entire spine, and every node is similarly confused, a
`SkippedToken` will be generated. 

In this case, however, a `SkippedToken` is not generated because `ClassNode` will know what `public` means.
Instead, the method will say "okay, I'm done", generate a `MissingToken`, and `public` will be subsequently handled
by the `ClassNode`.

##### Example 3
Building on Example 2... in the following case, no one knows how to handle an 
ampersand, and so this token will become a `SkippedToken`
```php
class A {
    function foo() {
        return;
    & // <- SkippedToken
    }
    public function bar() {

    }
}
```

##### Example 4
There are also some instances, where the aforementioned error handling wouldn't be
appropriate, and special-casing based on certain heuristics, such as 
whitespace, would be required. 

```php
if ($a >
    $b = new MyClass;
```

In this case, the user likely intended the type of `$b` to be `MyClass`. However,
because under normal circumstances, parsers will ignore whitespace, the example above
would produce the following tree, whic himplies that the `$b` assignment never happens.
```
SourceFileNode
- IfNode
  - OpenParen = Token
  - Expression = RelationalExpressionNode
    - Left: $a Token
    - Right: $b Token
  - CloseParen = MissingToken
- SkippedToken: '='
- ObjectCreationExpression
  - New: Token
  - ClassTypeDesignator: MyClass
  - Semicolon: Token
```

In our design, however, because every Token includes preceding whitespace trivia, 
our parser would be able to use whitespace as a heuristic to infer the user's likely
intentions. So rather than handling the error by generating a skipped `=` token,
we could instead generate a missing token for the right hand side of the
RelationalExpressionNode.

Note that for this error case, it is more of an art than a science. That is to say, we
would add special casing for anticipated scenarios, rather than construct some general-purpose rule.

#### Other notes
* Just as it's imporant to understand the assumptions that *will* hold true,
it is also important to understand the assumptions that will not hold true.
One such **non-invariant** is that not every token generated by the lexer ends up in the tree.

### Incremental Parsing

> Note: not yet implemented, but helps guide related architectural decisions / principles.

For large files, it can be expensive to reparse the tree on every edit. Instead,
we save time by reusing nodes from the old AST.

Rather than reparsing the entire token stream, we reparse only the portion corresponding
to the edit range. Such "invalidated" nodes include the directly-intersecting node, as well as 
(by definition) its parents. 

![image](https://cloud.githubusercontent.com/assets/762848/21580025/6557333e-cf88-11e6-9d45-9adf4f6c98d4.png)

In order to minimize the impact of edge cases, we avoid context-specific conditions in the parser.
For instance, let us apply the following transformation (making an edit that turns a compound statement into an
 class):
```php
/* BEFORE */
{
    function __construct() : int { }
}

/* AFTER */
class A {
    function __construct() : int { }
}
```

Technically, a constructor cannot include a return type. However, this constraint
limits the reusability of the node during incremental parsing. Such context-specific handling
during incremental parsing complicates the logic, and tends to result in a long-tail of 
hard-to-debug incremental parsing bugs, so we avoid it where possible. Instead we produce
diagnostics once the AST has already been produced. 

In addition to simply avoiding context-specific conditions where possible, we minimize
the number of edge cases by limiting the granularity of node-reuse. In the case of this parser,
we believe a reasonable balance is to limit granularity to a list `ParseContext`. 

## Open Questions
This approach, however, makes a few assumptions that we should validate upfront, if possible,
in order to minimize potential risk:
* [ ] **Assumption 1:** This approach will work on a wide range of user development environment configurations.
* [ ] **Assumption 2:** PHP can be sufficiently optimized to support aforementioned parser performance goals.
* [ ] **Assumption 3:** PHP 7 grammar is a superset of PHP5 grammar.
* [ ] **Assumption 4:** The PHP grammar described in `php/php-langspec` is complete.
* Anything else?

Some open Qs:
  * need some examples of large PHP applications to help benchmark
  * would PHP 5 provide sufficient perf?
  * what sort of data structures do we need? Ideally we'd throw everything into a struct. Anything better?

## Real world validation strategy
* benchmark against other parsers (investigate any instance of disagreement)
* perf benchmarks (should be able to get semantic information )