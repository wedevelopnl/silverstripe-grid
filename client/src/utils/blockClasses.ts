type BlockModifier<M extends string> = M | false | null | undefined;

export function createBlockClasses<M extends string>(block: string) {
  return (...modifiers: BlockModifier<M>[]): string => {
    const classes: string[] = [block];

    for (const modifier of modifiers) {
      if (modifier) {
        classes.push(`${block}--${modifier}`);
      }
    }

    return classes.join(' ');
  };
}
