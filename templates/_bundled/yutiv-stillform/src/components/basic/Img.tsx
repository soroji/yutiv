import React, { useState } from 'react';
import { safeMediaUrl, placeholder } from '../../support/storefront';

export interface ImgProps extends React.ImgHTMLAttributes<HTMLImageElement> {}

/**
 * 기본 이미지 컴포넌트
 */
export const Img: React.FC<ImgProps> = ({
  className = '',
  alt = '',
  src,
  onError,
  ...props
}) => {
  const [failed, setFailed] = useState<string>();
  const approved = safeMediaUrl(src);
  return (
    <img
      className={className}
      alt={alt}
      {...props}
      src={approved && failed !== src ? approved : placeholder}
      onError={event => { setFailed(src); onError?.(event); }}
    />
  );
};
